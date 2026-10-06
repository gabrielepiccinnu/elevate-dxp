<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Portal\Cart\CartStorage;
use ElevateDxp\Portal\Cart\ElementRef;
use ElevateDxp\Portal\Installer\PortalInstaller;
use ElevateDxp\Portal\Repository\CollectionRepository;
use ElevateDxp\Portal\Search\PortalSearchService;
use ElevateDxp\Portal\Security\CurrentOwner;

/**
 * "Portal search" report: DAM portal search with a session download cart.
 * Filters drive the configured search backend; global actions manage the session cart.
 */
final class PortalSearchResource extends AbstractAdminResource
{
    public function __construct(
        private readonly PortalSearchService $search,
        private readonly CartStorage $cart,
        private readonly CollectionRepository $collections,
        private readonly CurrentOwner $owner,
        private readonly ShareUrlGenerator $urls,
    ) {
    }

    public function getKey(): string
    {
        return 'portal_search';
    }

    public function getLabel(): string
    {
        return 'Portal search & cart';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_portal';
    }

    public function getPermission(): string
    {
        return PortalInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        $items = Field::textarea('items', 'Elements', ['required' => true, 'help' => 'asset:12, object:7 … (copy the "Ref" column; bare ids are assets)']);

        return [
            'panel' => 'report',
            'idProperty' => 'ref',
            'filters' => [
                Field::select('type', 'Type', ['asset' => 'Assets', 'object' => 'Data objects'], ['default' => 'asset']),
                Field::text('text', 'Text'),
                Field::text('class', 'Class (objects)'),
                Field::text('path', 'Folder'),
                Field::text('range_field', 'Range field'),
                Field::number('min', 'Min'),
                Field::number('max', 'Max'),
                Field::text('order_by', 'Order by'),
                Field::select('order_dir', 'Direction', ['ASC' => 'Ascending', 'DESC' => 'Descending'], ['default' => 'ASC']),
            ],
            'fields' => [
                Field::text('ref', 'Ref', ['width' => 110]),
                Field::text('key', 'Name'),
                Field::text('fullPath', 'Path', ['flex' => 2]),
                Field::text('subtype', 'Type / class', ['width' => 120]),
                Field::text('mimetype', 'MIME type', ['width' => 140]),
                Field::text('modified', 'Modified', ['width' => 150]),
            ],
            'actions' => [
                Action::global('cart_add', 'Add to cart', ['iconCls' => 'opendxp_icon_add', 'params' => [$items]]),
                Action::global('cart_view', 'Show cart', ['iconCls' => 'opendxp_icon_view']),
                Action::global('cart_remove', 'Remove from cart', ['iconCls' => 'opendxp_icon_minus', 'params' => [$items]]),
                Action::global('cart_clear', 'Clear cart', ['iconCls' => 'opendxp_icon_delete', 'confirm' => 'Remove every element from the cart?']),
                Action::global('cart_download', 'Download cart (ZIP)', ['iconCls' => 'opendxp_icon_download']),
                Action::global('cart_save', 'Save cart as collection', ['iconCls' => 'opendxp_icon_save', 'params' => [Field::text('name', 'Collection name', ['required' => true])]]),
            ],
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
        ];
    }

    public function list(array $query): array
    {
        $params = array_filter((array) ($query['filters'] ?? []), static fn ($v): bool => $v !== null && $v !== '');
        if (trim((string) ($query['q'] ?? '')) !== '' && !isset($params['text'])) {
            $params['text'] = (string) $query['q'];
        }
        $params['start'] = (int) ($query['start'] ?? 0);
        $params['limit'] = (int) ($query['limit'] ?? 25);

        $result = $this->search->search($this->search->queryFromArray($params));

        return ['data' => array_map(self::row(...), $result->items), 'total' => $result->total];
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        switch ($action) {
            case 'cart_add':
                foreach (ElementRef::parseList((string) ($params['items'] ?? '')) as $item) {
                    $this->cart->add($item['type'], $item['id']);
                }

                return Action::message(\sprintf('Cart: %d element(s).', $this->cart->count()));
            case 'cart_remove':
                foreach (ElementRef::parseList((string) ($params['items'] ?? '')) as $item) {
                    $this->cart->remove($item['type'], $item['id']);
                }

                return Action::message(\sprintf('Cart: %d element(s).', $this->cart->count()));
            case 'cart_view':
                $rows = array_map(static fn (array $i): array => ['ref' => ElementRef::format($i), 'type' => $i['type'], 'id' => $i['id']], $this->cart->all());

                return Action::table($rows, \sprintf('Cart (%d)', \count($rows)), ['ref', 'type', 'id']);
            case 'cart_clear':
                $this->cart->clear();

                return Action::message('Cart cleared.');
            case 'cart_download':
                if ($this->cart->count() === 0) {
                    throw new \InvalidArgumentException('The cart is empty.');
                }

                return Action::url($this->urls->cartDownload(), 'Preparing ZIP download…');
            case 'cart_save':
                $items = $this->cart->all();
                if ($items === []) {
                    throw new \InvalidArgumentException('The cart is empty.');
                }
                $id = $this->collections->create($this->owner->name(), (string) ($params['name'] ?? ''), $items);
                $this->cart->clear();

                return Action::message(\sprintf('Collection #%d created with %d element(s); cart cleared.', $id, \count($items)));
        }

        return parent::runAction($action, $id, $params);
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string, mixed>
     */
    public static function row(array $item): array
    {
        $modified = $item['modificationDate'] ?? null;

        return [
            'ref' => $item['type'].':'.$item['id'],
            'id' => $item['id'],
            'type' => $item['type'],
            'key' => $item['key'] ?? null,
            'fullPath' => $item['fullPath'] ?? null,
            'subtype' => $item['subtype'] ?? null,
            'mimetype' => $item['mimetype'] ?? null,
            'modified' => is_numeric($modified) ? date('Y-m-d H:i', (int) $modified) : null,
            'fields' => $item['fields'] ?? null,
        ];
    }
}
