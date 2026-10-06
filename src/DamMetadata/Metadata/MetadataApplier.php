<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\Metadata;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use OpenDxp\Model\Asset;
use OpenDxp\Model\User;

/**
 * Bulk-applies schema metadata to the assets of a folder (recursively) through the native Asset
 * metadata API. Only assets matching the schema (path prefix + asset types) are touched; when a
 * user is given, the native "save" permission is checked per asset.
 */
final class MetadataApplier
{
    public function __construct(
        private readonly SchemaService $schemas,
        private readonly AuditLoggerInterface $audit,
        private readonly int $batchSize = 200,
    ) {
    }

    /**
     * Sets one field (overwriting) on every asset matching the schema, scanning from the
     * schema's path prefix.
     *
     * @return array{matched:int,updated:int,error:?string}
     */
    public function apply(string $schema, string $field, mixed $value, string $actor = 'cli'): array
    {
        $prefix = (string) ($this->schemas->get($schema)['path_prefix'] ?? '/');
        $folder = $prefix === '' || $prefix === '/' ? '/' : rtrim($prefix, '/');
        $r = $this->applyToFolder($folder, $schema, $field, $value, true, null, $actor, false);

        return ['matched' => $r['matched'], 'updated' => $r['updated'], 'error' => $r['error']];
    }

    /**
     * @return array{matched:int,updated:int,unchanged:int,denied:int,failed:int,error:?string}
     */
    public function applyToFolder(
        string $folderPath,
        string $schema,
        ?string $field,
        mixed $value,
        bool $overwrite = false,
        ?User $user = null,
        string $actor = 'cli',
        bool $requireFolder = true,
    ): array {
        $result = ['matched' => 0, 'updated' => 0, 'unchanged' => 0, 'denied' => 0, 'failed' => 0, 'error' => null];

        try {
            // validates schema/field/value once, before touching any asset
            $this->schemas->plan($schema, [], $field, $value, true);
            $root = self::normalizeFolderPath($folderPath);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()] + $result;
        }

        if ($root !== '/') {
            $folder = Asset::getByPath($root);
            if (!$folder instanceof Asset\Folder) {
                if ($requireFolder) {
                    return ['error' => "Asset folder '$root' not found."] + $result;
                }
                // apply(): a path prefix that is not a folder (e.g. "/img/prod") -> scan its parent;
                // the schema prefix filter (assetMatches) still restricts the matched assets.
                $root = \dirname($root);
            }
        }

        $like = self::likePrefix($root);
        $lastId = 0;
        do {
            $listing = new Asset\Listing();
            $listing->setCondition('`path` LIKE ? AND `type` != ? AND `id` > ?', [$like, 'folder', $lastId]);
            $listing->setOrderKey('id');
            $listing->setOrder('ASC');
            $listing->setLimit($this->batchSize);
            $assets = $listing->getAssets();

            foreach ($assets as $asset) {
                $lastId = (int) $asset->getId();
                if (!$this->schemas->assetMatches($schema, $asset)) {
                    continue;
                }
                ++$result['matched'];
                if ($user !== null && !$asset->isAllowed('save', $user)) {
                    ++$result['denied'];

                    continue;
                }
                try {
                    $existing = array_values(array_unique(array_map(
                        static fn (array $m): string => (string) ($m['name'] ?? ''),
                        (array) $asset->getMetadata(null, null, false, true),
                    )));
                    $entries = $this->schemas->plan($schema, $existing, $field, $value, $overwrite);
                    if ($entries === []) {
                        ++$result['unchanged'];

                        continue;
                    }
                    foreach ($entries as $entry) {
                        $asset->addMetadata($entry['name'], $entry['type'], $entry['data']);
                    }
                    if ($user !== null) {
                        $asset->setUserModification($user->getId());
                    }
                    $asset->save();
                    ++$result['updated'];
                } catch (\Throwable) {
                    ++$result['failed']; // count the failure and continue with the next asset
                }
            }
        } while (\count($assets) === $this->batchSize);

        $this->audit->log(new AuditEvent('dam.metadata.bulk_apply', $user?->getName() ?? $actor, 'ok', [
            'folder' => $root, 'schema' => $schema, 'field' => $field,
        ] + array_diff_key($result, ['error' => true])));

        return $result;
    }

    /** "/a/b/" or "a/b" -> "/a/b"; rejects traversal and empty segments. */
    public static function normalizeFolderPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $s) {
            if ($s === '' || $s === '.' || $s === '..') {
                throw new \InvalidArgumentException("Invalid folder path '$path'.");
            }
        }

        return '/'.implode('/', $segments);
    }

    /** LIKE pattern for the "path" column of every asset below a folder (wildcards escaped). */
    public static function likePrefix(string $folder): string
    {
        $base = $folder === '/' ? '/' : $folder.'/';

        return addcslashes($base, '\\%_').'%';
    }
}
