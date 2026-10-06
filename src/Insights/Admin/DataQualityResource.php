<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Insights\DataQuality\QualityService;
use ElevateDxp\Insights\Installer\InsightsInstaller;

final class DataQualityResource extends AbstractAdminResource
{
    public function __construct(private readonly QualityService $quality)
    {
    }

    public function getKey(): string
    {
        return 'data-quality';
    }

    public function getLabel(): string
    {
        return 'Data Quality';
    }

    public function getGroup(): string
    {
        return 'Insights';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_quality';
    }

    public function getPermission(): string
    {
        return InsightsInstaller::DATA_QUALITY;
    }

    public function getSchema(): array
    {
        $classes = $this->quality->classes();

        return [
            'panel' => 'report',
            'filters' => [Field::select('class', 'Class', array_combine($classes, $classes) ?: [], ['required' => true])],
            'fields' => [
                Field::text('field', 'Field'),
                Field::number('filled', 'Filled'),
                Field::number('rate', 'Completeness %'),
            ],
            'chart' => ['x' => 'field', 'y' => ['rate']],
            'actions' => [
                Action::global('worst', 'Least complete records', ['params' => [Field::select('class', 'Class', array_combine($classes, $classes) ?: [], ['required' => true])]]),
            ],
        ];
    }

    public function list(array $query): array
    {
        $class = (string) ($query['filters']['class'] ?? '');
        if ($class === '') {
            return ['data' => [], 'total' => 0];
        }
        $report = $this->quality->report($class);
        $rows = array_merge([['field' => '(average completeness)', 'filled' => $report['total'], 'rate' => $report['averageCompleteness']]], $report['fields']);

        return ['data' => $rows, 'total' => \count($rows)];
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action !== 'worst') {
            return parent::runAction($action, $id, $params);
        }
        $report = $this->quality->report((string) ($params['class'] ?? ''));

        return Action::table(array_map(static fn (array $w) => ['id' => $w['id'], 'key' => $w['key'], 'missing' => implode(', ', $w['missing'])], $report['worst']), 'Least complete '.$report['class']);
    }
}
