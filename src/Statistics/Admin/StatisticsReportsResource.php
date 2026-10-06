<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Statistics\Installer\StatisticsInstaller;
use ElevateDxp\Statistics\Report\NativeReportReader;
use ElevateDxp\Statistics\Report\NativeReportSeeder;
use ElevateDxp\Statistics\Report\ReportRunner;
use OpenDxp\Model\User;
use OpenDxp\Security\User\TokenStorageUserResolver;

/**
 * Report catalogue: YAML-configured reports plus native OpenDXP Custom Reports (read-only).
 * Replaces the Studio statistics dashboard list; "Run" shows the rows, "Seed native Custom
 * Reports" creates the configured reports as native ones (idempotent).
 */
final class StatisticsReportsResource extends AbstractAdminResource
{
    public function __construct(
        private readonly ReportRunner $runner,
        private readonly NativeReportSeeder $seeder,
        private readonly NativeReportReader $native,
        private readonly ?TokenStorageUserResolver $userResolver = null,
        private readonly int $maxRows = 1000,
    ) {
    }

    public function getKey(): string
    {
        return 'statistics_reports';
    }

    public function getLabel(): string
    {
        return 'Statistics reports';
    }

    public function getGroup(): string
    {
        return 'Insights';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_statistics';
    }

    public function getPermission(): string
    {
        return StatisticsInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'idProperty' => 'id',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::text('id', 'ID', ['grid' => false, 'readOnly' => true]),
                Field::text('name', 'Name', ['readOnly' => true, 'width' => 200]),
                Field::text('label', 'Label', ['readOnly' => true, 'flex' => 1]),
                Field::select('source', 'Source', ['config' => 'YAML config', 'native' => 'Native Custom Report'], ['readOnly' => true, 'width' => 160]),
                Field::text('chart', 'Chart', ['readOnly' => true, 'width' => 80]),
                Field::text('x', 'X / label column', ['readOnly' => true]),
                Field::text('y', 'Y / value column', ['readOnly' => true]),
                Field::bool('native', 'Native report exists', ['readOnly' => true, 'width' => 140]),
                Field::code('sql', 'SQL (read-only)', ['readOnly' => true]),
            ],
            'actions' => [
                Action::record('run', 'Run report', ['iconCls' => 'opendxp_icon_play']),
                Action::global('seed_native', 'Seed native Custom Reports', [
                    'iconCls' => 'opendxp_icon_add',
                    'confirm' => 'Create the configured reports as native Custom Reports? Existing reports are not modified.',
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        return $this->paginate($this->rows(), $query, ['name', 'label', 'source']);
    }

    public function get(string $id): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        return match ($action) {
            'run' => $this->run($id ?? throw new \InvalidArgumentException('Select a report first.')),
            'seed_native' => $this->seedNative(),
            default => parent::runAction($action, $id, $params),
        };
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $nativeNames = [];
        $nativeRows = [];
        if ($this->canReadNative()) {
            try {
                foreach ($this->native->list($this->user()) as $n) {
                    $nativeNames[$n['name']] = true;
                    $nativeRows[] = ['id' => 'native:'.$n['name'], 'name' => $n['name'], 'label' => $n['label'],
                        'source' => 'native', 'chart' => $n['chart'], 'x' => $n['x'], 'y' => $n['y'], 'native' => true, 'sql' => ''];
                }
            } catch (\Throwable) {
                // Custom Reports bundle unavailable: show configured reports only.
            }
        }

        $rows = [];
        foreach ($this->runner->names() as $name) {
            $meta = $this->runner->meta($name);
            $rows[] = ['id' => 'config:'.$name, 'name' => $name, 'label' => $meta['label'], 'source' => 'config',
                'chart' => $meta['chart'], 'x' => $meta['x'], 'y' => $meta['y'], 'native' => isset($nativeNames[$name]), 'sql' => $meta['sql']];
        }
        foreach ($nativeRows as $row) {
            if (!$this->runner->has($row['name'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function run(string $id): array
    {
        [$source, $name] = array_pad(explode(':', $id, 2), 2, '');
        try {
            if ($source === 'config') {
                $data = $this->runner->run($name, $this->maxRows);
                $title = ($this->runner->meta($name)['label'] ?? $name).($data['truncated'] ? \sprintf(' (first %d rows)', $this->maxRows) : '');

                return Action::table($data['rows'], $title, $data['columns']);
            }
            if ($source === 'native') {
                if (!$this->canReadNative()) {
                    throw new \InvalidArgumentException('Running native Custom Reports requires the "reports" permission.');
                }
                $data = $this->native->run($name, $this->maxRows, $this->user());

                return Action::table($data['rows'], $name, $data['columns']);
            }
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Report failed: '.$e->getMessage(), 0, $e);
        }

        throw new \InvalidArgumentException(\sprintf('Unknown report "%s".', $id));
    }

    private function seedNative(): array
    {
        $user = $this->user();
        if ($user !== null && !$user->isAdmin() && !$user->isAllowed('reports_config')) {
            throw new \InvalidArgumentException('Seeding native Custom Reports requires the "reports_config" permission.');
        }
        try {
            $r = $this->seeder->seed();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Seeding failed: '.$e->getMessage(), 0, $e);
        }

        return Action::message(self::summary($r), true);
    }

    /** @param array{created:list<string>,skipped:list<string>,unsupported:array<string,string>} $r */
    public static function summary(array $r): string
    {
        $unsupported = [];
        foreach ($r['unsupported'] as $name => $reason) {
            $unsupported[] = "$name ($reason)";
        }

        return \sprintf('Native Custom Reports - created: %s; skipped (exist): %s; unsupported: %s',
            $r['created'] === [] ? '-' : implode(', ', $r['created']),
            $r['skipped'] === [] ? '-' : implode(', ', $r['skipped']),
            $unsupported === [] ? '-' : implode('; ', $unsupported),
        );
    }

    private function canReadNative(): bool
    {
        $user = $this->user();

        return $user === null || $user->isAdmin() || $user->isAllowed('reports') || $user->isAllowed('reports_config');
    }

    private function user(): ?User
    {
        return $this->userResolver?->getUser();
    }
}
