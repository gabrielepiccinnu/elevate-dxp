<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Experiments\Installer\ExperimentsInstaller;
use ElevateDxp\Experiments\Report\ExperimentReport;
use ElevateDxp\Experiments\Repository\ExperimentRepository;

final class ExperimentReportResource extends AbstractAdminResource
{
    public function __construct(
        private readonly ExperimentRepository $experiments,
        private readonly ExperimentReport $report,
    ) {
    }

    public function getKey(): string
    {
        return 'experiment-results';
    }

    public function getLabel(): string
    {
        return 'Experiment Results';
    }

    public function getGroup(): string
    {
        return 'Marketing';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_report';
    }

    public function getPermission(): string
    {
        return ExperimentsInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        $options = [];
        foreach ($this->experiments->findAll() as $e) {
            $options[$e->key] = \sprintf('%s (%s)', $e->name, $e->status);
        }

        return [
            'panel' => 'report',
            'filters' => [Field::select('experiment', 'Experiment', $options, ['required' => true])],
            'fields' => [
                Field::text('variant', 'Variant'),
                Field::bool('is_control', 'Control'),
                Field::number('weight', 'Weight', ['width' => 80]),
                Field::number('visitors', 'Visitors'),
                Field::number('forced', 'Forced by rule'),
                Field::number('conversions', 'Conversions'),
                Field::number('conversion_rate', 'Conv. rate %'),
                Field::number('uplift', 'Uplift vs control %'),
                Field::number('p_value', 'p-value'),
                Field::number('confidence', 'Confidence %'),
                Field::bool('significant', 'Significant (p<0.05)'),
            ],
            'chart' => ['x' => 'variant', 'y' => ['conversion_rate']],
        ];
    }

    public function list(array $query): array
    {
        $key = (string) ($query['filters']['experiment'] ?? '');
        $experiment = $key !== '' ? $this->experiments->findByKey($key) : null;
        if ($experiment === null) {
            return ['data' => [], 'total' => 0];
        }
        $rows = $this->report->build($experiment);

        return ['data' => $rows, 'total' => \count($rows)];
    }
}
