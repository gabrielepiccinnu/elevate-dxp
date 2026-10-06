<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search;

use ElevateDxp\Portal\Search\Listing\ConditionBuilder;
use ElevateDxp\Portal\Search\Listing\ListingFactory;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Listing\AbstractListing;

/**
 * Default portal search backend over plain OpenDXP listings (no index required).
 *
 * Search semantics:
 *  - full text   → LIKE on configured columns (each term must match one column), plus asset metadata;
 *  - ranges      → numeric >= / <= on allow-listed fields;
 *  - folders     → excluded for assets (type != folder); object listings only return objects;
 *  - path        → subtree restriction on the "path" column;
 *  - ordering    → allow-listed fields only; paging via limit/offset.
 *
 * It is SQL-LIKE based, so it suits small and medium repositories; register another
 * {@see SearchBackendInterface} (e.g. AdvancedObjectSearch) for large catalogues.
 */
final class ListingSearchBackend implements SearchBackendInterface
{
    public const NAME = 'listing';

    private const ASSET_SYSTEM_FIELDS = ['id', 'filename', 'path', 'mimetype', 'type', 'creationDate', 'modificationDate'];
    private const OBJECT_SYSTEM_FIELDS = ['id', 'key', 'path', 'published', 'creationDate', 'modificationDate'];
    private const METADATA_CLAUSE = '`id` IN (SELECT `cid` FROM `assets_metadata` WHERE `data` LIKE ?)';

    /**
     * @param array{asset_fields?:list<string>,asset_metadata?:bool,default_object_fields?:list<string>,object_fields?:array<string,list<string>>,locale?:?string,restrict_assets_to_allowed_paths?:bool} $config
     * @param list<string>                                                                                                                                                                                $allowedAssetPaths
     */
    public function __construct(
        private readonly ListingFactory $listings,
        private readonly array $config = [],
        private readonly array $allowedAssetPaths = [],
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->type === SearchQuery::TYPE_ASSET || $query->className !== null;
    }

    public function search(SearchQuery $query): SearchResult
    {
        return $query->type === SearchQuery::TYPE_ASSET ? $this->searchAssets($query) : $this->searchObjects($query);
    }

    /** @return array{0:string,1:list<mixed>} */
    public function assetCondition(SearchQuery $query): array
    {
        $allowed = array_merge(self::ASSET_SYSTEM_FIELDS, $this->assetFields());
        $cb = new ConditionBuilder();
        if ($query->excludeFolders) {
            $cb->notEquals('type', 'folder');
        }
        $cb->text($this->assetFields(), $query->text, ($this->config['asset_metadata'] ?? true) ? [self::METADATA_CLAUSE] : []);
        foreach ($query->ranges as $range) {
            $cb->range($this->allowed($range->field, $allowed), $range->min, $range->max);
        }
        $cb->pathPrefix('path', $query->pathPrefix);
        if ($this->config['restrict_assets_to_allowed_paths'] ?? false) {
            $cb->anyPathPrefix('path', $this->allowedAssetPaths);
        }

        return $cb->build();
    }

    /** @return array{0:string,1:list<mixed>} */
    public function objectCondition(SearchQuery $query): array
    {
        $class = (string) $query->className;
        $cb = new ConditionBuilder();
        $cb->text($this->objectFields($class), $query->text);
        foreach ($query->ranges as $range) {
            $cb->range($this->objectField($class, $range->field), $range->min, $range->max);
        }
        $cb->pathPrefix('path', $query->pathPrefix);

        return $cb->build();
    }

    private function searchAssets(SearchQuery $query): SearchResult
    {
        $listing = $this->listings->assets();
        [$condition, $params] = $this->assetCondition($query);
        $this->apply($listing, $condition, $params, $query, array_merge(self::ASSET_SYSTEM_FIELDS, $this->assetFields()), 'filename');

        $items = [];
        foreach ($listing->getData() ?? [] as $asset) {
            if ($asset instanceof Asset) {
                $items[] = self::normalizeAsset($asset);
            }
        }

        return new SearchResult($items, $listing->count(), $query->page, $query->pageSize);
    }

    private function searchObjects(SearchQuery $query): SearchResult
    {
        $class = (string) $query->className;
        $listing = $this->listings->objects($class);
        if (($this->config['locale'] ?? null) !== null) {
            $listing->setLocale((string) $this->config['locale']);
        }
        [$condition, $params] = $this->objectCondition($query);
        $allowedOrder = self::OBJECT_SYSTEM_FIELDS;
        foreach (array_keys($query->orderBy) as $field) {
            if (!\in_array($field, $allowedOrder, true)) {
                $allowedOrder[] = $this->objectField($class, $field);
            }
        }
        $this->apply($listing, $condition, $params, $query, $allowedOrder, 'key');

        $displayFields = $this->objectFields($class);
        $items = [];
        foreach ($listing->getData() ?? [] as $object) {
            if ($object instanceof Concrete) {
                $items[] = self::normalizeObject($object, $displayFields);
            }
        }

        return new SearchResult($items, $listing->count(), $query->page, $query->pageSize);
    }

    /**
     * @param list<mixed>  $params
     * @param list<string> $allowedOrder
     */
    private function apply(AbstractListing $listing, string $condition, array $params, SearchQuery $query, array $allowedOrder, string $defaultOrder): void
    {
        if ($condition !== '') {
            $listing->setCondition($condition, $params);
        }
        $keys = [];
        $dirs = [];
        foreach ($query->orderBy as $field => $dir) {
            $keys[] = $this->allowed($field, $allowedOrder);
            $dirs[] = $dir === 'DESC' ? 'DESC' : 'ASC';
        }
        if ($keys === []) {
            $keys = [$defaultOrder];
            $dirs = ['ASC'];
        }
        $listing->setOrderKey($keys);
        $listing->setOrder($dirs);
        $listing->setLimit($query->pageSize);
        $listing->setOffset($query->offset());
    }

    /** @return list<string> */
    private function assetFields(): array
    {
        return array_values($this->config['asset_fields'] ?? ['filename', 'path']);
    }

    /** @return list<string> */
    private function objectFields(string $class): array
    {
        $fields = $this->config['object_fields'][$class] ?? $this->config['default_object_fields'] ?? ['key'];

        return array_values(array_map(fn (string $f): string => $this->objectField($class, $f), $fields));
    }

    private function objectField(string $class, string $field): string
    {
        ConditionBuilder::quoteIdentifier($field); // syntax check
        if (\in_array($field, self::OBJECT_SYSTEM_FIELDS, true) || $this->listings->hasObjectField($class, $field)) {
            return $field;
        }

        throw new \InvalidArgumentException(\sprintf('Field "%s" does not exist on class "%s".', $field, $class));
    }

    /** @param list<string> $allowed */
    private function allowed(string $field, array $allowed): string
    {
        if (!\in_array($field, $allowed, true)) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" is not searchable.', $field));
        }

        return $field;
    }

    /** @return array<string,mixed> */
    public static function normalizeAsset(Asset $asset): array
    {
        return [
            'id' => $asset->getId(),
            'type' => SearchQuery::TYPE_ASSET,
            'key' => $asset->getFilename(),
            'fullPath' => $asset->getFullPath(),
            'subtype' => $asset->getType(),
            'mimetype' => $asset->getMimeType(),
            'modificationDate' => $asset->getModificationDate(),
        ];
    }

    /**
     * @param list<string> $displayFields
     *
     * @return array<string,mixed>
     */
    public static function normalizeObject(Concrete $object, array $displayFields = []): array
    {
        $row = [
            'id' => $object->getId(),
            'type' => SearchQuery::TYPE_OBJECT,
            'key' => $object->getKey(),
            'fullPath' => $object->getFullPath(),
            'subtype' => $object->getClassName(),
            'published' => $object->isPublished(),
            'modificationDate' => $object->getModificationDate(),
        ];
        $values = [];
        foreach ($displayFields as $field) {
            $getter = 'get'.ucfirst($field);
            if (!isset($row[$field]) && method_exists($object, $getter)) {
                $value = $object->$getter();
                if (\is_scalar($value) || $value === null) {
                    $values[$field] = $value;
                }
            }
        }
        if ($values !== []) {
            $row['fields'] = $values;
        }

        return $row;
    }
}
