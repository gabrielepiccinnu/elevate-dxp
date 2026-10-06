<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Report;

use OpenDxp\Bundle\CustomReportsBundle\Tool\Config;

/**
 * Seeds the configured statistics reports as NATIVE OpenDXP Custom Reports
 * (OpenDxp\Bundle\CustomReportsBundle\Tool\Config), so they also appear under Reports.
 * Idempotent: existing reports are never overwritten.
 */
final class NativeReportSeeder
{
    public const GROUP = 'Elevate DXP Statistics';

    /** @param array<string,array{label?:string,sql:string,chart?:string,x?:?string,y?:?string,columns?:list<string>}> $reports */
    public function __construct(
        private readonly array $reports,
        private readonly ReportRunner $runner,
    ) {
    }

    /**
     * @param list<string>|null $only restrict to these report names
     *
     * @return array{created:list<string>,skipped:list<string>,unsupported:array<string,string>}
     */
    public function seed(?array $only = null): array
    {
        $result = ['created' => [], 'skipped' => [], 'unsupported' => []];
        foreach ($this->reports as $name => $report) {
            $name = (string) $name;
            if ($only !== null && !\in_array($name, $only, true)) {
                continue;
            }
            if (Config::getByName($name) !== null) {
                $result['skipped'][] = $name;

                continue;
            }
            $reason = self::unsupportedReason((string) ($report['sql'] ?? ''));
            if ($reason !== null) {
                $result['unsupported'][$name] = $reason;

                continue;
            }
            try {
                $columns = $this->runner->columns($name);
            } catch (\Throwable) {
                $columns = $report['columns'] ?? [];
            }

            $definition = self::buildDefinition($name, $report, $columns);
            $cfg = new Config();
            $cfg->setName($definition['name']);
            $cfg->setNiceName($definition['niceName']);
            $cfg->setGroup($definition['group']);
            $cfg->setSql($definition['sql']);
            $cfg->setDataSourceConfig($definition['dataSourceConfig']);
            $cfg->setColumnConfiguration($definition['columnConfiguration']);
            $cfg->setChartType($definition['chartType']);
            $cfg->setXAxis($definition['xAxis']);
            $cfg->setYAxis($definition['yAxis']);
            $cfg->setPieColumn($definition['pieColumn']);
            $cfg->setPieLabelColumn($definition['pieLabelColumn']);
            $cfg->save();
            $result['created'][] = $name;
        }

        return $result;
    }

    /** Why a query cannot become a native SQL custom report, or null when it can. */
    public static function unsupportedReason(string $sql): ?string
    {
        try {
            $clean = ReadOnlySqlGuard::assertReadOnly($sql);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        // The native SQL adapter prefixes anything not starting with SELECT, so CTEs break.
        if (!str_starts_with(strtolower($clean), 'select')) {
            return 'WITH (CTE) queries are not supported by the native SQL custom report adapter.';
        }

        return null;
    }

    /**
     * Pure mapping from a Elevate DXP report definition to native Custom Report properties.
     *
     * @param array{label?:string,sql:string,chart?:string,x?:?string,y?:?string} $report
     * @param list<string>                                                        $columns
     *
     * @return array{name:string,niceName:string,group:string,sql:string,dataSourceConfig:list<array<string,string>>,columnConfiguration:list<array<string,mixed>>,chartType:string,xAxis:?string,yAxis:?list<string>,pieColumn:?string,pieLabelColumn:?string}
     */
    public static function buildDefinition(string $name, array $report, array $columns): array
    {
        $sql = ReadOnlySqlGuard::assertReadOnly((string) $report['sql']);
        $chart = (string) ($report['chart'] ?? 'bar');
        $x = isset($report['x']) && $report['x'] !== '' ? (string) $report['x'] : null;
        $y = isset($report['y']) && $report['y'] !== '' ? (string) $report['y'] : null;
        $label = (string) ($report['label'] ?? '');

        return [
            'name' => $name,
            'niceName' => $label !== '' ? $label : $name,
            'group' => self::GROUP,
            'sql' => $sql,
            'dataSourceConfig' => [['type' => 'sql', 'sql' => $sql, 'from' => '', 'where' => '', 'groupby' => '']],
            'columnConfiguration' => array_map(static fn (string $c): array => [
                'name' => $c,
                'display' => true,
                'export' => true,
                'order' => true,
                'width' => '',
                'label' => '',
                'filter' => '',
                'displayType' => '',
                'filter_drilldown' => '',
                'columnAction' => '',
            ], array_values(array_unique(array_map('strval', $columns)))),
            'chartType' => $chart === 'none' ? '' : $chart,
            'xAxis' => $chart === 'pie' || $chart === 'none' ? null : $x,
            'yAxis' => $chart === 'pie' || $chart === 'none' || $y === null ? null : [$y],
            'pieColumn' => $chart === 'pie' ? $y : null,
            'pieLabelColumn' => $chart === 'pie' ? $x : null,
        ];
    }
}
