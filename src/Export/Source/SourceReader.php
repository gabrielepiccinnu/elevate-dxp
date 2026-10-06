<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Source;

use ElevateDxp\Core\Contract\FieldMapperInterface;
use ElevateDxp\Export\Contract\SourceReaderInterface;
use OpenDxp\Model\Asset;

/**
 * Reads a configured source (published data objects of one class, or non-folder assets) in
 * chunks through OpenDXP listings and maps each element explicitly with the core field mapper.
 */
final class SourceReader implements SourceReaderInterface
{
    public function __construct(private readonly FieldMapperInterface $mapper)
    {
    }

    public function read(array $source, int $chunkSize, ?int $limit = null): array
    {
        $fields = (array) ($source['fields'] ?? []);
        $chunkSize = max(1, $chunkSize);
        if ($limit !== null && $limit < 1) {
            return [];
        }

        return ($source['type'] ?? '') === 'asset'
            ? $this->readAssets($fields, $chunkSize, $limit)
            : $this->readObjects((string) ($source['class'] ?? ''), $fields, $chunkSize, $limit);
    }

    /**
     * Resolves the listing class of a data object class name, or null when invalid/unknown.
     */
    public static function objectListingClass(string $class): ?string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $class)) {
            return null;
        }
        $listingClass = 'OpenDxp\\Model\\DataObject\\'.$class.'\\Listing';

        return class_exists($listingClass) ? $listingClass : null;
    }

    /**
     * @param array<string,string> $fields
     *
     * @return list<array<string,mixed>>
     */
    private function readObjects(string $class, array $fields, int $chunkSize, ?int $limit): array
    {
        $listingClass = self::objectListingClass($class);
        if ($listingClass === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown data object class "%s".', $class));
        }
        $rows = [];
        $offset = 0;
        do {
            /** @var \OpenDxp\Model\DataObject\Listing\Concrete $listing */
            $listing = new $listingClass();
            $listing->setCondition('published = 1');
            $listing->setOrderKey('id');
            $listing->setOrder('ASC');
            $listing->setLimit($this->batchSize($chunkSize, $limit, \count($rows)));
            $listing->setOffset($offset);
            $batch = $listing->getObjects();
            foreach ($batch as $object) {
                $rows[] = $this->mapper->map($object, $fields);
            }
            $offset += \count($batch);
        } while (\count($batch) === $chunkSize && ($limit === null || \count($rows) < $limit));

        return $rows;
    }

    /**
     * @param array<string,string> $fields
     *
     * @return list<array<string,mixed>>
     */
    private function readAssets(array $fields, int $chunkSize, ?int $limit): array
    {
        $rows = [];
        $offset = 0;
        do {
            $listing = new Asset\Listing();
            $listing->setCondition("type != 'folder'");
            $listing->setOrderKey('id');
            $listing->setOrder('ASC');
            $listing->setLimit($this->batchSize($chunkSize, $limit, \count($rows)));
            $listing->setOffset($offset);
            $batch = $listing->getAssets();
            foreach ($batch as $asset) {
                $rows[] = $this->mapper->map($asset, $fields);
            }
            $offset += \count($batch);
        } while (\count($batch) === $chunkSize && ($limit === null || \count($rows) < $limit));

        return $rows;
    }

    private function batchSize(int $chunkSize, ?int $limit, int $read): int
    {
        return $limit === null ? $chunkSize : max(1, min($chunkSize, $limit - $read));
    }
}
