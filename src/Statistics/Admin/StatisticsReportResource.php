<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Statistics\Installer\StatisticsInstaller;
use ElevateDxp\Statistics\Report\ReportRunner;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * One "report" panel (table + chart) per configured report. Registered by
 * StatisticsModule for every entry of elevate_dxp_statistics.reports.
 *
 * Columns come from the report "columns" option or are derived from the query once and cached,
 * because the admin menu builds every schema on load.
 */
final class StatisticsReportResource extends AbstractAdminResource
{
    public const KEY_PREFIX = 'statistics_report_';

    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(
        private readonly string $name,
        private readonly ReportRunner $runner,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY_PREFIX.$this->name;
    }

    public function getLabel(): string
    {
        return 'Statistics: '.($this->runner->meta($this->name)['label'] ?? $this->name);
    }

    public function getGroup(): string
    {
        return 'Insights';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_report';
    }

    public function getPermission(): string
    {
        return StatisticsInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        $meta = $this->runner->meta($this->name) ?? ['chart' => 'none', 'x' => null, 'y' => null];
        $fields = [];
        foreach ($this->columns() as $column) {
            $fields[] = $column === $meta['y']
                ? Field::number($column, $column, ['flex' => 1])
                : Field::text($column, $column, ['flex' => 1]);
        }

        $schema = ['panel' => 'report', 'fields' => $fields, 'filters' => [], 'actions' => []];
        // The core report panel renders a bar chart; line/bar both map to it, pie/none show the table only.
        if (\in_array($meta['chart'], ['bar', 'line'], true) && $meta['x'] !== null && $meta['y'] !== null) {
            $schema['chart'] = ['x' => $meta['x'], 'y' => [$meta['y']]];
        }

        return $schema;
    }

    public function list(array $query): array
    {
        try {
            $data = $this->runner->run($this->name);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Report failed: '.$e->getMessage(), 0, $e);
        }

        return $this->paginate($data['rows'], $query, $data['columns']);
    }

    /** @return list<string> */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $meta = $this->runner->meta($this->name);
        if ($meta === null) {
            return $this->columns = [];
        }
        if ($meta['columns'] !== []) {
            return $this->columns = $meta['columns'];
        }

        try {
            $derive = fn (): array => $this->runner->columns($this->name);
            $columns = $this->cache !== null
                ? $this->cache->get('edxp_statistics_columns_'.md5($this->name."\0".$meta['sql']), static function (ItemInterface $item) use ($derive): array {
                    $columns = $derive();
                    $item->expiresAfter($columns === [] ? 300 : 86400);

                    return $columns;
                })
                : $derive();
        } catch (\Throwable) {
            // Broken query or DB unavailable: fall back to the chart columns; list() shows the error.
            $columns = array_values(array_filter([$meta['x'], $meta['y']], static fn ($c): bool => $c !== null && $c !== ''));
        }

        return $this->columns = $columns;
    }
}
