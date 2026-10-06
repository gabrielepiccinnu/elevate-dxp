<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Cart;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Portal\Security\PortalPolicy;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\Concrete;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

/**
 * Streams a ZIP of the asset binaries for a set of cart/collection items. Objects contribute the
 * asset referenced by the configured image field. Every asset is policy-gated (deny-by-default).
 */
final class ZipBuilder
{
    public const MAX_ITEMS = 1000;

    public function __construct(
        private readonly PortalPolicy $policy,
        private readonly AuditLoggerInterface $audit,
        private readonly string $objectImageField = 'image',
    ) {
    }

    /** @param list<array{type:string,id:int}> $items */
    public function stream(array $items, string $filename, string $actor): StreamedResponse
    {
        $assets = $this->resolveAssets(\array_slice($items, 0, self::MAX_ITEMS));

        $response = new StreamedResponse(function () use ($assets): void {
            $zip = new ZipStream(outputName: 'export.zip', sendHttpHeaders: false);
            $used = [];
            foreach ($assets as $asset) {
                $stream = $asset->getStream();
                if (!\is_resource($stream)) {
                    continue;
                }
                $zip->addFileFromStream(self::uniqueName((string) $asset->getFilename(), (int) $asset->getId(), $used), $stream);
            }
            $zip->finish();
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.self::safe($filename).'.zip"');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $this->audit->log(new AuditEvent('portal.zip', $actor, 'ok', [
            'requested' => \count($items), 'included' => \count($assets), 'file' => $filename,
        ]));

        return $response;
    }

    /**
     * @param list<array{type:string,id:int}> $items
     *
     * @return list<Asset>
     */
    public function resolveAssets(array $items): array
    {
        $assets = [];
        foreach ($items as $it) {
            $asset = $it['type'] === 'asset' ? Asset::getById((int) $it['id']) : $this->objectAsset((int) $it['id']);
            if ($asset instanceof Asset && $this->policy->canDownload($asset)) {
                $assets[$asset->getId()] = $asset; // dedup by id
            }
        }

        return array_values($assets);
    }

    private function objectAsset(int $id): ?Asset
    {
        $obj = Concrete::getById($id);
        $getter = 'get'.ucfirst($this->objectImageField);
        if (!$obj instanceof Concrete || !method_exists($obj, $getter)) {
            return null;
        }
        $img = $obj->$getter();

        return $img instanceof Asset ? $img : null;
    }

    /** @param array<string,bool> $used */
    public static function uniqueName(string $filename, int $id, array &$used): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'asset-'.$id;
        }
        $candidate = $name;
        $n = 1;
        while (isset($used[$candidate])) {
            $dot = strrpos($name, '.');
            $candidate = $dot === false || $dot === 0 ? $name.'-'.$n : substr($name, 0, $dot).'-'.$n.substr($name, $dot);
            ++$n;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    public static function safe(string $s): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $s), '-') ?: 'export';
    }
}
