<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Admin;

/**
 * A backend feature exposed in the "Elevate DXP" admin menu.
 *
 * The ExtJS framework in the core bundle renders a panel purely from getSchema(), so most
 * bundles ship no JavaScript at all. Schema shape:
 *
 *   [
 *     'panel'      => 'crud' | 'report' | 'custom',
 *     'idProperty' => 'id',
 *     'fields'     => [Field::text('name', 'Name', ['required' => true]), ...],
 *     'filters'    => [...fields shown above a report...],
 *     'actions'    => [Action::record('run', 'Run'), Action::global('import', 'Import')],
 *     'canCreate'  => bool, 'canEdit' => bool, 'canDelete' => bool,
 *     'jsClass'    => 'elevatedxp.x.Panel'   // only for panel=custom
 *     'chart'      => ['x' => 'variant', 'y' => ['conversion_rate']]  // optional, report panel
 *   ]
 *
 * Action results are arrays understood by the UI: message, rows+columns, text, html, url, reload.
 */
interface AdminResourceInterface
{
    public const TAG = 'elevate_dxp.admin_resource';

    public function getKey(): string;

    public function getLabel(): string;

    /** Menu group, e.g. "Marketing", "Data", "Integration". */
    public function getGroup(): string;

    public function getIconCls(): string;

    /** Permission key required to see and use the resource (admins always pass). */
    public function getPermission(): string;

    public function getSchema(): array;

    /**
     * @param array{start?:int, limit?:int, sort?:string, dir?:string, q?:string, filters?:array<string,mixed>} $query
     *
     * @return array{data: list<array<string,mixed>>, total: int}
     */
    public function list(array $query): array;

    public function get(string $id): ?array;

    public function save(array $data): array;

    public function delete(string $id): void;

    public function runAction(string $action, ?string $id, array $params): array;
}
