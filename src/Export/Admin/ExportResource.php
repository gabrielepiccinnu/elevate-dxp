<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Export\Installer\ExportInstaller;
use ElevateDxp\Export\Runner\ExportRunner;

/**
 * Admin view of the YAML-defined export jobs (read-only; editing stays in config) with
 * "Run" and "Preview" actions. Replaces the legacy Studio export panel.
 */
final class ExportResource extends AbstractAdminResource
{
    public const MAX_PREVIEW = 200;

    public function __construct(private readonly ExportRunner $runner)
    {
    }

    public function getKey(): string
    {
        return 'exports';
    }

    public function getLabel(): string
    {
        return 'Exports';
    }

    public function getGroup(): string
    {
        return 'Data';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_export';
    }

    public function getPermission(): string
    {
        return ExportInstaller::PERMISSION;
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
                Field::text('name', 'Job', ['readOnly' => true, 'flex' => 1]),
                Field::text('description', 'Description', ['readOnly' => true, 'flex' => 2]),
                Field::text('format', 'Format', ['readOnly' => true, 'width' => 80]),
                Field::text('source', 'Source', ['readOnly' => true, 'width' => 110]),
                Field::text('class', 'Class', ['readOnly' => true, 'width' => 140]),
                Field::text('target', 'Target', ['readOnly' => true, 'flex' => 2]),
                Field::keyvalue('fields', 'Field mapping (output => accessor)', ['readOnly' => true]),
            ],
            'actions' => [
                Action::record('run', 'Run export', [
                    'confirm' => 'Run this export now and write it to its target?',
                ]),
                Action::record('preview', 'Preview', [
                    'iconCls' => 'opendxp_icon_preview',
                    'params' => [
                        Field::number('limit', 'Rows', ['default' => 20, 'help' => 'Max '.self::MAX_PREVIEW]),
                        Field::select('as', 'Show as', ['table' => 'Table', 'rendered' => 'Rendered output'], ['default' => 'table']),
                    ],
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        return $this->paginate($this->runner->listJobs(), $query, ['name', 'description', 'class', 'target']);
    }

    public function get(string $id): ?array
    {
        foreach ($this->runner->listJobs() as $job) {
            if ($job['name'] === $id) {
                return $job;
            }
        }

        return null;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        $job = (string) $id;
        if ($job === '' || !$this->runner->has($job)) {
            throw new \InvalidArgumentException('Select an existing export job.');
        }

        return match ($action) {
            'run' => $this->run($job, self::actor()),
            'preview' => $this->preview($job, $params),
            default => parent::runAction($action, $id, $params),
        };
    }

    /** Audit actor: the logged-in admin user (never taken from request input). */
    public static function actor(): string
    {
        try {
            $user = \OpenDxp\Tool\Admin::getCurrentUser();
        } catch (\Throwable) {
            $user = null;
        }

        return $user !== null ? 'admin:'.$user->getName() : 'admin';
    }

    private function run(string $job, string $actor): array
    {
        try {
            $result = $this->runner->run($job, $actor);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \DomainException('Export failed: '.$e->getMessage(), 0, $e);
        }

        return Action::message(\sprintf('Exported %d rows to %s', $result['rows'], $result['location']));
    }

    private function preview(string $job, array $params): array
    {
        $limit = (int) ($params['limit'] ?? 20);
        $limit = max(1, min(self::MAX_PREVIEW, $limit > 0 ? $limit : 20));
        try {
            $preview = $this->runner->preview($job, $limit);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \DomainException('Preview failed: '.$e->getMessage(), 0, $e);
        }

        if (($params['as'] ?? 'table') === 'rendered') {
            return Action::text($preview['content'], \sprintf('Preview: %s (%s, %d rows)', $job, $preview['format'], \count($preview['rows'])), $preview['format']);
        }
        if ($preview['rows'] === []) {
            return Action::message('The source returned no rows.');
        }

        return Action::table(self::flatten($preview['rows']), \sprintf('Preview: %s (%d rows)', $job, \count($preview['rows'])));
    }

    /**
     * Grid cells must be scalars.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array<string,scalar|null>>
     */
    public static function flatten(array $rows): array
    {
        return array_map(static fn (array $row): array => array_map(
            static fn ($v) => \is_array($v) ? implode(', ', array_map(static fn ($x): string => \is_scalar($x) || $x === null ? (string) $x : (string) json_encode($x), $v)) : $v,
            $row,
        ), $rows);
    }
}
