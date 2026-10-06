<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Provider;

use ElevateDxp\Core\Contract\FieldMapperInterface;
use ElevateDxp\Core\Contract\ResourceProviderInterface;
use OpenDxp\Model\DataObject\Concrete;

/** Exposes published data objects of one configured class through OpenDXP listings (paginated). */
final class DataObjectProvider implements ResourceProviderInterface
{
    public function __construct(private readonly FieldMapperInterface $mapper)
    {
    }

    public function type(): string
    {
        return 'data_object';
    }

    public function list(array $endpoint, array $fields, int $page, int $limit): array
    {
        $listingClass = self::classFqcn($endpoint, '\\Listing');
        if ($listingClass === null) {
            return ['items' => [], 'total' => 0];
        }

        /** @var \OpenDxp\Model\DataObject\Listing\Concrete $listing */
        $listing = new $listingClass();
        $listing->setCondition('published = 1');
        $listing->setOrderKey('id');
        $listing->setOrder('ASC');
        $total = (int) $listing->getTotalCount();
        $listing->setLimit($limit);
        $listing->setOffset(($page - 1) * $limit);

        $items = [];
        foreach ($listing->getObjects() as $object) {
            $items[] = $this->mapper->map($object, $fields);
        }

        return ['items' => $items, 'total' => $total];
    }

    public function get(array $endpoint, array $fields, int $id): ?array
    {
        $fqcn = self::classFqcn($endpoint);
        if ($fqcn === null) {
            return null;
        }
        $object = $fqcn::getById($id);
        // Only objects of the configured class are reachable through an endpoint (the legacy bundle
        // fell back to any object id, which could leak objects of other classes).
        if (!$object instanceof $fqcn || !$object instanceof Concrete || !$object->isPublished()) {
            return null;
        }

        return $this->mapper->map($object, $fields);
    }

    /**
     * @param array<string,mixed> $endpoint
     *
     * @return class-string|null
     */
    public static function classFqcn(array $endpoint, string $suffix = ''): ?string
    {
        $class = (string) ($endpoint['class'] ?? '');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $class)) {
            return null;
        }
        $fqcn = 'OpenDxp\\Model\\DataObject\\'.$class.$suffix;

        return class_exists($fqcn) ? $fqcn : null;
    }
}
