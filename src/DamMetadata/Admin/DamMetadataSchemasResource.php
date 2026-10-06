<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\DamMetadata\Installer\DamMetadataInstaller;
use ElevateDxp\DamMetadata\Metadata\MetadataApplier;
use ElevateDxp\DamMetadata\Metadata\PredefinedSync;
use ElevateDxp\DamMetadata\Metadata\SchemaService;
use OpenDxp\Model\Asset;
use OpenDxp\Model\User;
use OpenDxp\Security\User\TokenStorageUserResolver;

/**
 * YAML-defined metadata schemas (read-only, GitOps) with actions: sync to native predefined
 * metadata, bulk apply to a folder, list native predefined metadata, inspect an asset.
 * Replaces the Studio "DAM Metadata" panel.
 */
final class DamMetadataSchemasResource extends AbstractAdminResource
{
    public function __construct(
        private readonly SchemaService $schemas,
        private readonly PredefinedSync $sync,
        private readonly MetadataApplier $applier,
        private readonly ?TokenStorageUserResolver $userResolver = null,
    ) {
    }

    public function getKey(): string
    {
        return 'dam_metadata_schemas';
    }

    public function getLabel(): string
    {
        return 'DAM metadata schemas';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_dam';
    }

    public function getPermission(): string
    {
        return DamMetadataInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        $schemaOptions = [];
        foreach ($this->schemas->names() as $name) {
            $schemaOptions[$name] = $this->schemas->label($name);
        }

        return [
            'panel' => 'crud',
            'idProperty' => 'id',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::text('id', 'Name', ['readOnly' => true, 'width' => 180]),
                Field::text('label', 'Label', ['readOnly' => true, 'flex' => 1]),
                Field::text('path_prefix', 'Path prefix', ['readOnly' => true, 'width' => 200]),
                Field::text('asset_types', 'Asset types', ['readOnly' => true, 'width' => 160]),
                Field::text('fields', 'Fields', ['readOnly' => true, 'flex' => 2]),
                Field::text('predefined', 'Predefined synced', ['readOnly' => true, 'width' => 140]),
            ],
            'actions' => [
                Action::record('fields', 'Show fields', ['iconCls' => 'opendxp_icon_info']),
                Action::global('sync_predefined', 'Sync predefined metadata', [
                    'iconCls' => 'opendxp_icon_reload',
                    'params' => [
                        Field::select('schema', 'Schema', ['' => '(all schemas)'] + $schemaOptions),
                    ],
                ]),
                Action::global('apply_folder', 'Apply to folder', [
                    'iconCls' => 'opendxp_icon_apply',
                    'params' => [
                        Field::element('folder', 'Asset folder', 'asset', ['required' => true, 'help' => 'Assets in this folder and its subfolders that match the schema.']),
                        Field::select('schema', 'Schema', $schemaOptions, ['required' => true]),
                        Field::text('field', 'Field', ['help' => 'Leave empty to initialize all fields that have a default value.']),
                        Field::text('value', 'Value'),
                        Field::bool('overwrite', 'Overwrite existing values', ['default' => false]),
                    ],
                ]),
                Action::global('predefined', 'Native predefined metadata', ['iconCls' => 'opendxp_icon_metadata']),
                Action::global('show_asset', 'Show asset metadata', [
                    'iconCls' => 'opendxp_icon_search',
                    'params' => [Field::element('asset', 'Asset', 'asset', ['required' => true])],
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        $existing = null;
        try {
            $existing = $this->sync->nativeDefinitions();
        } catch (\Throwable) {
            // predefined metadata store not readable: leave the column empty
        }

        $rows = [];
        foreach ($this->schemas->all() as $name => $cfg) {
            $name = (string) $name;
            $defs = $this->schemas->predefinedDefinitions($name);
            $rows[] = [
                'id' => $name,
                'label' => $this->schemas->label($name),
                'path_prefix' => (string) ($cfg['path_prefix'] ?? '/'),
                'asset_types' => implode(', ', $cfg['asset_types'] ?? []) ?: 'any',
                'fields' => implode(', ', array_map(static fn (array $f): string => $f['name'].':'.($f['type'] ?? 'input'), $cfg['fields'] ?? [])),
                'predefined' => $existing === null ? '' : \sprintf('%d/%d', \count($defs) - \count($this->schemas->missingDefinitions($defs, $existing)), \count($defs)),
            ];
        }

        return $this->paginate($rows, $query, ['id', 'label', 'path_prefix']);
    }

    public function get(string $id): ?array
    {
        foreach ($this->list([])['data'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        return match ($action) {
            'fields' => $this->fields($id ?? throw new \InvalidArgumentException('Select a schema first.')),
            'sync_predefined' => $this->syncPredefined($params),
            'apply_folder' => $this->applyFolder($params),
            'predefined' => Action::table($this->sync->nativeDefinitions(), 'Native predefined asset metadata',
                ['name', 'type', 'group', 'targetSubtype', 'language', 'description', 'config']),
            'show_asset' => $this->showAsset((string) ($params['asset'] ?? '')),
            default => parent::runAction($action, $id, $params),
        };
    }

    private function fields(string $schema): array
    {
        $cfg = $this->schemas->get($schema) ?? throw new \InvalidArgumentException("Unknown schema '$schema'.");
        $rows = array_map(fn (array $f): array => [
            'name' => (string) $f['name'],
            'type' => (string) ($f['type'] ?? 'input'),
            'native_type' => $this->schemas->nativeType((string) ($f['type'] ?? 'input')),
            'label' => (string) ($f['label'] ?? ''),
            'options' => implode(', ', $f['options'] ?? []),
            'default' => $f['default'] ?? null,
        ], $cfg['fields'] ?? []);

        return Action::table($rows, 'Fields of '.$this->schemas->label($schema), ['name', 'type', 'native_type', 'label', 'options', 'default']);
    }

    private function syncPredefined(array $params): array
    {
        $user = $this->user();
        if ($user !== null && !$user->isAdmin() && !$user->isAllowed('asset_metadata')) {
            throw new \InvalidArgumentException('Syncing predefined metadata requires the "asset_metadata" permission.');
        }
        $schema = trim((string) ($params['schema'] ?? ''));
        try {
            $r = $this->sync->sync($schema === '' ? null : $schema, $user?->getName() ?? 'admin');
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Sync failed: '.$e->getMessage(), 0, $e);
        }

        return Action::message(\sprintf('Predefined metadata - created %d (%s), already present %d.',
            \count($r['created']), $r['created'] === [] ? '-' : implode(', ', $r['created']), $r['existing']), true);
    }

    private function applyFolder(array $params): array
    {
        $user = $this->user();
        if ($user !== null && !$user->isAdmin() && !$user->isAllowed('assets')) {
            throw new \InvalidArgumentException('Applying metadata requires the "assets" permission.');
        }
        $folder = trim((string) ($params['folder'] ?? ''));
        $schema = trim((string) ($params['schema'] ?? ''));
        if ($folder === '' || $schema === '') {
            throw new \InvalidArgumentException('Folder and schema are required.');
        }
        $field = trim((string) ($params['field'] ?? ''));
        $value = $params['value'] ?? null;
        $overwrite = filter_var($params['overwrite'] ?? false, \FILTER_VALIDATE_BOOL);

        $r = $this->applier->applyToFolder($folder, $schema, $field === '' ? null : $field, $value, $overwrite, $user, $user?->getName() ?? 'admin');
        if ($r['error'] !== null) {
            throw new \InvalidArgumentException($r['error']);
        }

        return Action::message(self::summary($r));
    }

    /** @param array{matched:int,updated:int,unchanged:int,denied:int,failed:int} $r */
    public static function summary(array $r): string
    {
        return \sprintf('Matched %d asset(s): updated %d, unchanged %d, denied %d, failed %d.',
            $r['matched'], $r['updated'], $r['unchanged'], $r['denied'], $r['failed']);
    }

    private function showAsset(string $path): array
    {
        $asset = ctype_digit($path) ? Asset::getById((int) $path) : Asset::getByPath($path);
        if (!$asset instanceof Asset) {
            throw new \InvalidArgumentException('Asset not found.');
        }
        $user = $this->user();
        if ($user !== null && !$asset->isAllowed('view', $user)) {
            throw new \InvalidArgumentException('You are not allowed to view this asset.');
        }
        $rows = [];
        foreach ((array) $asset->getMetadata(null, null, false, true) as $m) {
            $data = $m['data'] ?? null;
            $rows[] = [
                'name' => (string) ($m['name'] ?? '?'),
                'type' => (string) ($m['type'] ?? '?'),
                'language' => (string) ($m['language'] ?? ''),
                'value' => \is_scalar($data) || $data === null ? (string) $data : (string) json_encode($data),
            ];
        }

        return Action::table($rows, 'Metadata of '.$asset->getRealFullPath(), ['name', 'type', 'language', 'value']);
    }

    private function user(): ?User
    {
        return $this->userResolver?->getUser();
    }
}
