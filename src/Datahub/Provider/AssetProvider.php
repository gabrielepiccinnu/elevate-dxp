<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Provider;

use ElevateDxp\Core\Contract\FieldMapperInterface;
use ElevateDxp\Core\Contract\ResourceProviderInterface;
use OpenDxp\Model\Asset;

/** Exposes assets through OpenDXP listings (paginated). Folders are excluded. */
final class AssetProvider implements ResourceProviderInterface
{
    public function __construct(private readonly FieldMapperInterface $mapper)
    {
    }

    public function type(): string
    {
        return 'asset';
    }

    public function list(array $endpoint, array $fields, int $page, int $limit): array
    {
        $listing = new Asset\Listing();
        $listing->setCondition("type != 'folder'");
        $listing->setOrderKey('id');
        $listing->setOrder('ASC');
        $total = (int) $listing->getTotalCount();
        $listing->setLimit($limit);
        $listing->setOffset(($page - 1) * $limit);

        $items = [];
        foreach ($listing->getAssets() as $asset) {
            $items[] = $this->mapper->map($asset, $fields);
        }

        return ['items' => $items, 'total' => $total];
    }

    public function get(array $endpoint, array $fields, int $id): ?array
    {
        $asset = Asset::getById($id);
        if (!$asset instanceof Asset || $asset->getType() === 'folder') {
            return null;
        }

        return $this->mapper->map($asset, $fields);
    }
}
