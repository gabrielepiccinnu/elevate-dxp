<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Export\Admin\ExportResource;
use ElevateDxp\Feed\Installer\FeedInstaller;
use ElevateDxp\Feed\Runner\FeedRunner;

/**
 * Admin view of the YAML-defined product feeds (read-only; editing stays in config) with
 * "Validate", "Preview" and "Export" actions. Replaces the legacy Studio feed panel.
 */
final class FeedResource extends AbstractAdminResource
{
    public const MAX_PREVIEW = 200;

    public function __construct(private readonly FeedRunner $runner)
    {
    }

    public function getKey(): string
    {
        return 'feeds';
    }

    public function getLabel(): string
    {
        return 'Product feeds';
    }

    public function getGroup(): string
    {
        return 'Marketing';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_feed';
    }

    public function getPermission(): string
    {
        return FeedInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'idProperty' => 'name',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::text('name', 'Feed', ['readOnly' => true, 'flex' => 1]),
                Field::text('template', 'Template', ['readOnly' => true, 'width' => 140]),
                Field::text('source', 'Source', ['readOnly' => true, 'width' => 110]),
                Field::text('class', 'Class', ['readOnly' => true, 'width' => 120]),
                Field::text('currency', 'Currency', ['readOnly' => true, 'width' => 80]),
                Field::text('target', 'Target', ['readOnly' => true, 'flex' => 1]),
                Field::bool('public', 'Public URL', ['readOnly' => true]),
                Field::text('publicPath', 'Public path', ['readOnly' => true, 'flex' => 2]),
                Field::text('linkPattern', 'Link pattern', ['readOnly' => true, 'grid' => false]),
                Field::keyvalue('mappings', 'Mappings (feed field => accessor)', ['readOnly' => true]),
                Field::keyvalue('static', 'Static values', ['readOnly' => true]),
            ],
            'actions' => [
                Action::record('validate', 'Validate', ['iconCls' => 'opendxp_icon_success']),
                Action::record('preview', 'Preview', [
                    'iconCls' => 'opendxp_icon_preview',
                    'params' => [
                        Field::number('limit', 'Rows', ['default' => 20, 'help' => 'Max '.self::MAX_PREVIEW]),
                        Field::select('as', 'Show as', ['table' => 'Table', 'rendered' => 'Rendered feed'], ['default' => 'table']),
                    ],
                ]),
                Action::record('export', 'Export', [
                    'iconCls' => 'opendxp_icon_export',
                    'confirm' => 'Render this feed and write it to its target now?',
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        return $this->paginate($this->runner->describe(), $query, ['name', 'template', 'class', 'target']);
    }

    public function get(string $id): ?array
    {
        foreach ($this->runner->describe() as $row) {
            if ($row['name'] === $id) {
                return $row;
            }
        }

        return null;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        $feed = (string) $id;
        if ($feed === '' || !$this->runner->has($feed)) {
            throw new \InvalidArgumentException('Select an existing feed.');
        }

        try {
            return match ($action) {
                'validate' => $this->validate($feed),
                'preview' => $this->preview($feed, $params),
                'export' => $this->export($feed),
                default => parent::runAction($action, $id, $params),
            };
        } catch (\InvalidArgumentException|\DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \DomainException(\sprintf('Feed %s failed: %s', $action, $e->getMessage()), 0, $e);
        }
    }

    private function validate(string $feed): array
    {
        $built = $this->runner->build($feed);
        $total = \count($built['rows']);
        if ($built['issues'] === []) {
            return Action::message(\sprintf('All %d rows pass required-field validation.', $total));
        }
        $rows = array_map(static fn (array $issue): array => [
            'row' => $issue['index'],
            'id' => \is_scalar($built['rows'][$issue['index']]['id'] ?? null) ? (string) $built['rows'][$issue['index']]['id'] : '',
            'missing' => implode(', ', $issue['missing']),
        ], $built['issues']);

        return Action::table($rows, \sprintf('%s: %d of %d rows with missing required fields', $feed, \count($rows), $total), ['row', 'id', 'missing']);
    }

    private function preview(string $feed, array $params): array
    {
        $limit = (int) ($params['limit'] ?? 20);
        $limit = max(1, min(self::MAX_PREVIEW, $limit > 0 ? $limit : 20));
        $built = $this->runner->build($feed, $limit);

        if (($params['as'] ?? 'table') === 'rendered') {
            $template = $this->runner->template($feed);
            $config = $this->runner->feed($feed);
            $content = $template->render($built['rows'], ['currency' => $config['currency'] ?? 'EUR', 'channel' => $config['channel'] ?? []]);

            return Action::text($content, \sprintf('Preview: %s (%d rows)', $feed, \count($built['rows'])), $template->extension());
        }
        if ($built['rows'] === []) {
            return Action::message('The feed source returned no rows.');
        }

        $issueByRow = [];
        foreach ($built['issues'] as $issue) {
            $issueByRow[$issue['index']] = implode(', ', $issue['missing']);
        }
        $rows = [];
        foreach (ExportResource::flatten($built['rows']) as $i => $row) {
            $rows[] = ['_missing' => $issueByRow[$i] ?? ''] + $row;
        }

        return Action::table($rows, \sprintf('Preview: %s (%d rows, %d with issues)', $feed, \count($rows), \count($built['issues'])));
    }

    private function export(string $feed): array
    {
        $result = $this->runner->export($feed, ExportResource::actor());
        $msg = \sprintf('Feed "%s": %d rows written to %s', $feed, $result['rows'], $result['location']);
        if ($result['issues'] > 0) {
            $msg .= \sprintf(' (%d row(s) with validation issues)', $result['issues']);
        }

        return Action::message($msg);
    }
}
