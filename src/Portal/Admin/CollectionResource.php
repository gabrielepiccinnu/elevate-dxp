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
use ElevateDxp\Portal\Security\CurrentOwner;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\Concrete;

/**
 * Portal collections (named carts), owner-scoped (admins see all): create/edit items,
 * ZIP download, share, revoke, load into the cart.
 */
final class CollectionResource extends AbstractAdminResource
{
    /** @param array{ttl_days:int,max_ttl_days:int} $share */
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly CartStorage $cart,
        private readonly CurrentOwner $owner,
        private readonly ShareUrlGenerator $urls,
        private readonly array $share = ['ttl_days' => 7, 'max_ttl_days' => 90],
    ) {
    }

    public function getKey(): string
    {
        return 'portal_collections';
    }

    public function getLabel(): string
    {
        return 'Portal collections';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_dam';
    }

    public function getPermission(): string
    {
        return PortalInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'idProperty' => 'id',
            'fields' => [
                Field::id(),
                Field::text('name', 'Name', ['required' => true]),
                Field::text('owner', 'Owner', ['readOnly' => true, 'virtual' => true, 'width' => 120]),
                Field::tags('items', 'Elements', ['virtual' => true, 'help' => 'asset:12, object:7 … (bare ids are assets)']),
                Field::number('item_count', 'Elements', ['readOnly' => true, 'virtual' => true, 'width' => 90]),
                Field::bool('share_active', 'Shared', ['readOnly' => true, 'virtual' => true]),
                Field::datetime('share_expires_at', 'Share expires', ['readOnly' => true, 'virtual' => true]),
                Field::datetime('created_at', 'Created', ['readOnly' => true, 'virtual' => true]),
            ],
            'actions' => [
                Action::record('items', 'Show elements', ['iconCls' => 'opendxp_icon_view']),
                Action::record('download', 'Download ZIP', ['iconCls' => 'opendxp_icon_download']),
                Action::record('share', 'Create share link', ['iconCls' => 'opendxp_icon_share', 'params' => [
                    Field::number('days', 'Valid for (days)', ['default' => $this->share['ttl_days'], 'help' => 'A new link replaces the previous one.']),
                ]]),
                Action::record('revoke_share', 'Revoke share link', ['iconCls' => 'opendxp_icon_cancel', 'confirm' => 'The current share link will stop working. Continue?']),
                Action::record('to_cart', 'Add to cart', ['iconCls' => 'opendxp_icon_add']),
                Action::global('from_cart', 'New from cart', ['iconCls' => 'opendxp_icon_import', 'params' => [Field::text('name', 'Collection name', ['required' => true])]]),
            ],
            'canCreate' => true,
            'canEdit' => true,
            'canDelete' => true,
        ];
    }

    public function list(array $query): array
    {
        $res = $this->collections->search(
            $this->owner->scope(),
            (string) ($query['q'] ?? ''),
            (int) ($query['start'] ?? 0),
            (int) ($query['limit'] ?? 50),
            (string) ($query['sort'] ?? 'id'),
            (string) ($query['dir'] ?? 'DESC'),
        );

        return ['data' => array_map(self::row(...), $res['rows']), 'total' => $res['total']];
    }

    public function get(string $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        $items = $this->collections->items((int) $row['id']);
        $row['items'] = array_map(ElementRef::format(...), $items);
        $row['item_count'] = \count($items);

        return self::row($row);
    }

    public function save(array $data): array
    {
        $items = \array_key_exists('items', $data) && $data['items'] !== null ? ElementRef::parseList(\is_array($data['items']) ? array_map('strval', $data['items']) : (string) $data['items']) : null;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Field "Name" is required.');
        }
        $id = (string) ($data['id'] ?? '');
        if ($id !== '' && $id !== '0') {
            $row = $this->find($id) ?? throw new \InvalidArgumentException('Record not found: '.$id);
            $this->collections->rename((int) $row['id'], $name, $this->owner->scope());
            if ($items !== null) {
                $this->collections->replaceItems((int) $row['id'], $items);
            }
            $newId = (int) $row['id'];
        } else {
            $newId = $this->collections->create($this->owner->name(), $name, $items ?? []);
        }

        return $this->get((string) $newId) ?? [];
    }

    public function delete(string $id): void
    {
        if (!$this->collections->delete((int) $id, $this->owner->scope())) {
            throw new \InvalidArgumentException('Record not found: '.$id);
        }
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action === 'from_cart') {
            $items = $this->cart->all();
            if ($items === []) {
                throw new \InvalidArgumentException('The cart is empty: add elements in "Portal search & cart" first.');
            }
            $newId = $this->collections->create($this->owner->name(), (string) ($params['name'] ?? ''), $items);
            $this->cart->clear();

            return Action::message(\sprintf('Collection #%d created with %d element(s).', $newId, \count($items)), true);
        }

        $row = $this->find((string) $id) ?? throw new \InvalidArgumentException('Please select a collection.');
        $cid = (int) $row['id'];

        switch ($action) {
            case 'items':
                return Action::table(array_map(self::describe(...), $this->collections->items($cid)), (string) $row['name'], ['ref', 'label', 'path']);
            case 'download':
                return Action::url($this->urls->collectionDownload($cid), 'Preparing ZIP download…');
            case 'share':
                $days = ShareTtl::days($params['days'] ?? null, $this->share['ttl_days'], $this->share['max_ttl_days']);
                $token = $this->collections->share($cid, $this->owner->scope(), $days) ?? throw new \InvalidArgumentException('Record not found.');
                $url = $this->urls->share($token);

                return Action::html(
                    '<p>Guest link (valid '.$days.' day(s), no login required):</p>'
                    .'<p><input type="text" readonly style="width:100%" value="'.htmlspecialchars($url, \ENT_QUOTES).'"></p>'
                    .'<p><a href="'.htmlspecialchars($url, \ENT_QUOTES).'" target="_blank" rel="noopener noreferrer">Open</a></p>',
                    'Share link',
                ) + ['reload' => true, 'message' => 'Share link created.'];
            case 'revoke_share':
                $this->collections->revokeShare($cid, $this->owner->scope());

                return Action::message('Share link revoked.', true);
            case 'to_cart':
                $items = $this->collections->items($cid);
                foreach ($items as $item) {
                    $this->cart->add($item['type'], $item['id']);
                }

                return Action::message(\sprintf('%d element(s) added; cart now holds %d.', \count($items), $this->cart->count()));
        }

        return parent::runAction($action, $id, $params);
    }

    /** @return array<string,mixed>|null */
    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->collections->find((int) $id, $this->owner->scope()) : null;
    }

    /**
     * Never exposes the raw share token in the collections grid.
     *
     * @param array<string, mixed> $r collection row from the repository
     *
     * @return array<string, mixed>
     */
    private static function row(array $r): array
    {
        $out = [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'owner' => (string) ($r['owner'] ?? ''),
            'item_count' => (int) ($r['item_count'] ?? 0),
            'share_active' => isset($r['share_active']) ? (bool) $r['share_active'] : (!empty($r['share_expires_at']) && strtotime((string) $r['share_expires_at']) > time()),
            'share_expires_at' => $r['share_expires_at'] ?? null,
            'created_at' => $r['created_at'] ?? null,
        ];
        if (isset($r['items'])) {
            $out['items'] = $r['items'];
        }

        return $out;
    }

    /**
     * @param array{type:string,id:int} $item
     *
     * @return array{ref: string, label: string, path: string|null}
     */
    private static function describe(array $item): array
    {
        $el = $item['type'] === 'asset' ? Asset::getById($item['id']) : Concrete::getById($item['id']);

        return [
            'ref' => ElementRef::format($item),
            'label' => $el instanceof Asset ? $el->getFilename() : ($el instanceof Concrete ? $el->getKey() : '(missing)'),
            'path' => $el?->getFullPath(),
        ];
    }
}
