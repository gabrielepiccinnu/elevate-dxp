<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Portal\Installer\PortalInstaller;
use ElevateDxp\Portal\Repository\SavedViewRepository;
use ElevateDxp\Portal\Search\PortalSearchService;
use ElevateDxp\Portal\Security\CurrentOwner;

/**
 * Saved portal searches (per user). Params use the same keys as the "Portal search" filters and
 * are validated/normalised through SearchQuery before storage. "Run" executes the view.
 *
 * @phpstan-import-type SavedView from SavedViewRepository
 */
final class SavedViewResource extends AbstractAdminResource
{
    public function __construct(
        private readonly SavedViewRepository $views,
        private readonly PortalSearchService $search,
        private readonly CurrentOwner $owner,
    ) {
    }

    public function getKey(): string
    {
        return 'portal_saved_views';
    }

    public function getLabel(): string
    {
        return 'Portal saved views';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'opendxp_icon_search';
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
                Field::text('name', 'Name', ['required' => true]),
                Field::text('owner', 'Owner', ['readOnly' => true, 'virtual' => true, 'width' => 120]),
                Field::text('summary', 'Query', ['readOnly' => true, 'virtual' => true, 'flex' => 2, 'hidden' => true]),
                Field::json('params', 'Search parameters', [
                    'required' => true,
                    'help' => '{"type":"asset|object","q":"text","class":"Product","path":"/products","ranges":[{"field":"price","min":10,"max":99}],"order_by":"key","order_dir":"ASC","page_size":24}',
                ]),
                Field::datetime('created_at', 'Created', ['readOnly' => true, 'virtual' => true]),
            ],
            'actions' => [
                Action::record('run', 'Run view', ['iconCls' => 'opendxp_icon_search']),
            ],
            'canCreate' => true,
            'canEdit' => true,
            'canDelete' => true,
        ];
    }

    public function list(array $query): array
    {
        $res = $this->views->search(
            $this->owner->scope(),
            (string) ($query['q'] ?? ''),
            (int) ($query['start'] ?? 0),
            (int) ($query['limit'] ?? 50),
            (string) ($query['sort'] ?? 'id'),
            (string) ($query['dir'] ?? 'DESC'),
        );

        return ['data' => array_map(self::row(...), $res['rows']), 'total' => $res['total']];
    }

    public function get(string $id): ?array
    {
        $row = ctype_digit($id) ? $this->views->find((int) $id, $this->owner->scope()) : null;

        return $row === null ? null : self::row($row);
    }

    public function save(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Field "Name" is required.');
        }
        $params = $data['params'] ?? null;
        if (\is_string($params)) {
            $params = $params === '' ? null : json_decode($params, true, 16, \JSON_THROW_ON_ERROR);
        }
        if (!\is_array($params)) {
            throw new \InvalidArgumentException('Search parameters must be a JSON object.');
        }
        $normalised = $this->search->queryFromArray($params)->toArray();

        $id = (string) ($data['id'] ?? '');
        if ($id !== '' && $id !== '0') {
            $existing = $this->get($id) ?? throw new \InvalidArgumentException('Record not found: '.$id);
            $this->views->update((int) $existing['id'], $this->owner->scope(), $name, $normalised);
            $id = (string) $existing['id'];
        } else {
            $id = (string) $this->views->create($this->owner->name(), $name, $normalised);
        }

        return $this->get($id) ?? [];
    }

    public function delete(string $id): void
    {
        if ($this->get($id) === null) {
            throw new \InvalidArgumentException('Record not found: '.$id);
        }
        $this->views->delete((int) $id, $this->owner->scope());
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action !== 'run') {
            return parent::runAction($action, $id, $params);
        }
        $view = $this->get((string) $id) ?? throw new \InvalidArgumentException('Please select a saved view.');
        $result = $this->search->search($this->search->queryFromArray($view['params']));
        $rows = array_map(static function (array $item): array {
            $row = PortalSearchResource::row($item);
            unset($row['fields']);

            return $row;
        }, $result->items);

        return Action::table($rows, \sprintf('%s — %d result(s), page %d', $view['name'], $result->total, $result->page), ['ref', 'key', 'fullPath', 'subtype', 'mimetype', 'modified']);
    }

    /**
     * @param SavedView $r
     *
     * @return array<string, mixed>
     */
    private static function row(array $r): array
    {
        $p = $r['params'];

        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'owner' => (string) $r['owner'],
            'summary' => trim(($p['type'] ?? 'asset').' '.($p['class'] ?? '').' '.(isset($p['q']) ? '"'.$p['q'].'"' : '').' '.($p['path'] ?? '')),
            'params' => $p,
            'created_at' => $r['created_at'],
        ];
    }
}
