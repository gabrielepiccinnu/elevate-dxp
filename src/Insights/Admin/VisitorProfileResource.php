<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Admin;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Admin\AbstractDbalResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Insights\Cdp\CdpService;
use ElevateDxp\Insights\Installer\InsightsInstaller;

final class VisitorProfileResource extends AbstractDbalResource
{
    public function __construct(Connection $db, private readonly CdpService $cdp)
    {
        parent::__construct($db);
    }

    public function getKey(): string
    {
        return 'visitor-profiles';
    }

    public function getLabel(): string
    {
        return 'Visitor Profiles (CDP)';
    }

    public function getGroup(): string
    {
        return 'Insights';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_profile';
    }

    public function getPermission(): string
    {
        return InsightsInstaller::CDP;
    }

    protected function getTable(): string
    {
        return 'edxp_visitor_profile';
    }

    /** @return list<string> */
    protected function getSearchColumns(): array
    {
        return ['visitor_id', 'last_url', 'utm_last', 'target_groups'];
    }

    /** @return array{0: string, 1: 'ASC'|'DESC'} */
    protected function getDefaultSort(): array
    {
        return ['last_seen', 'DESC'];
    }

    public function list(array $query): array
    {
        $result = parent::list($query);
        $result['data'] = $this->cdp->enrich($result['data']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'idProperty' => 'visitor_id',
            'canCreate' => false,
            'canEdit' => false,
            'fields' => [
                Field::text('visitor_id', 'Visitor', ['width' => 250]),
                Field::datetime('first_seen', 'First seen', ['width' => 140]),
                Field::datetime('last_seen', 'Last seen', ['width' => 140]),
                Field::number('sessions', 'Sessions', ['width' => 80]),
                Field::number('pageviews', 'Page views', ['width' => 90]),
                Field::number('conversions', 'Conversions', ['width' => 90, 'virtual' => true]),
                Field::number('experiments', 'Experiments', ['width' => 90, 'virtual' => true]),
                Field::text('segments', 'Segments', ['virtual' => true, 'width' => 220]),
                Field::text('last_url', 'Last URL'),
                Field::text('referrer', 'Referrer', ['grid' => false]),
                Field::json('utm_first', 'Campaign (first touch)'),
                Field::json('utm_last', 'Campaign (last touch)'),
                Field::json('target_groups', 'Target groups'),
                Field::text('targeting_visitor_id', 'Targeting visitor id', ['grid' => false]),
            ],
            'actions' => [
                Action::record('timeline', 'Timeline', ['iconCls' => 'opendxp_icon_history']),
                Action::global('segments', 'Segments overview', ['iconCls' => 'elevatedxp_icon_report']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function runAction(string $action, ?string $id, array $params): array
    {
        return match ($action) {
            'timeline' => $id !== null ? Action::table($this->cdp->timeline($id), 'Timeline '.$id) : throw new \InvalidArgumentException('Select a visitor.'),
            'segments' => Action::table($this->cdp->summary(), 'Segments'),
            default => parent::runAction($action, $id, $params),
        };
    }

    /** GDPR erasure: deletes profile, events and assignments of the visitor. */
    public function delete(string $id): void
    {
        $this->db->transactional(function (Connection $db) use ($id): void {
            foreach (['edxp_visitor_profile', 'edxp_event', 'edxp_assignment'] as $table) {
                $db->delete($table, ['visitor_id' => $id]);
            }
        });
    }
}
