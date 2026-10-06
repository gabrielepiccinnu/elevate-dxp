<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Portal\Installer\PortalInstaller;
use ElevateDxp\Portal\Repository\CollectionRepository;
use ElevateDxp\Portal\Security\CurrentOwner;

/**
 * Overview of guest share links (collections with a token): open, extend, revoke, purge expired.
 * Links are created from "Portal collections". Owner-scoped (admins see all).
 */
final class ShareLinkResource extends AbstractAdminResource
{
    /** @param array{ttl_days:int,max_ttl_days:int} $share */
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly CurrentOwner $owner,
        private readonly ShareUrlGenerator $urls,
        private readonly array $share = ['ttl_days' => 7, 'max_ttl_days' => 90],
    ) {
    }

    public function getKey(): string
    {
        return 'portal_share_links';
    }

    public function getLabel(): string
    {
        return 'Portal share links';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'opendxp_icon_share';
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
                Field::text('name', 'Collection', ['readOnly' => true]),
                Field::text('owner', 'Owner', ['readOnly' => true, 'width' => 120]),
                Field::select('status', 'Status', ['active' => 'Active', 'expired' => 'Expired'], ['readOnly' => true, 'width' => 90]),
                Field::datetime('share_expires_at', 'Expires', ['readOnly' => true]),
                Field::number('item_count', 'Elements', ['readOnly' => true, 'width' => 90]),
                Field::text('url', 'Guest URL', ['readOnly' => true, 'flex' => 3]),
            ],
            'actions' => [
                Action::record('open', 'Open', ['iconCls' => 'opendxp_icon_preview']),
                Action::record('extend', 'Extend', ['iconCls' => 'opendxp_icon_time', 'params' => [
                    Field::number('days', 'Valid for (days from now)', ['default' => $this->share['ttl_days']]),
                ]]),
                Action::record('revoke', 'Revoke', ['iconCls' => 'opendxp_icon_cancel', 'confirm' => 'The link will stop working immediately. Continue?']),
                Action::global('purge_expired', 'Remove expired links', ['iconCls' => 'opendxp_icon_clear_cache', 'confirm' => 'Remove every expired share token?']),
            ],
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
        ];
    }

    public function list(array $query): array
    {
        $sort = (string) ($query['sort'] ?? 'share_expires_at');
        $res = $this->collections->search(
            $this->owner->scope(),
            (string) ($query['q'] ?? ''),
            (int) ($query['start'] ?? 0),
            (int) ($query['limit'] ?? 50),
            $sort === 'status' || $sort === 'url' ? 'share_expires_at' : $sort,
            (string) ($query['dir'] ?? 'DESC'),
            true,
        );

        return ['data' => array_map($this->row(...), $res['rows']), 'total' => $res['total']];
    }

    public function get(string $id): ?array
    {
        $row = ctype_digit($id) ? $this->collections->find((int) $id, $this->owner->scope()) : null;

        return $row === null || empty($row['share_token']) ? null : $this->row($row + ['item_count' => \count($this->collections->items((int) $row['id']))]);
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        $scope = $this->owner->scope();
        if ($action === 'purge_expired') {
            return Action::message(\sprintf('%d expired link(s) removed.', $this->collections->revokeExpired($scope)), true);
        }
        $row = $this->get((string) $id) ?? throw new \InvalidArgumentException('Please select a share link.');

        switch ($action) {
            case 'open':
                return Action::url((string) $row['url']);
            case 'extend':
                $days = ShareTtl::days($params['days'] ?? null, $this->share['ttl_days'], $this->share['max_ttl_days']);
                $this->collections->extendShare((int) $row['id'], $scope, $days);

                return Action::message(\sprintf('Link valid for %d more day(s).', $days), true);
            case 'revoke':
                $this->collections->revokeShare((int) $row['id'], $scope);

                return Action::message('Share link revoked.', true);
        }

        return parent::runAction($action, $id, $params);
    }

    private function row(array $r): array
    {
        $active = isset($r['share_active']) ? (bool) $r['share_active'] : (!empty($r['share_expires_at']) && strtotime((string) $r['share_expires_at']) > time());

        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'owner' => (string) $r['owner'],
            'status' => $active ? 'active' : 'expired',
            'share_expires_at' => $r['share_expires_at'] ?? null,
            'item_count' => (int) ($r['item_count'] ?? 0),
            'url' => $this->urls->share((string) $r['share_token']),
        ];
    }
}
