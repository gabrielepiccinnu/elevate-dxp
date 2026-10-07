<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Datahub\Installer\DatahubInstaller;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only list of the native OpenDXP Data Hub configurations (open-dxp/data-hub-bundle),
 * the GraphQL headless API. When the Data Hub bundle is not enabled, the grid shows a single
 * row explaining how to install it. Secrets (API keys) are never exposed; only whether one is set.
 */
final class GraphqlConfigurationResource extends AbstractAdminResource
{
    public const DATAHUB_CONFIGURATION = 'OpenDxp\\Bundle\\DataHubBundle\\Configuration';
    public const DATAHUB_BUNDLE = 'OpenDxpDataHubBundle';
    public const INSTALL_HINT = 'composer require open-dxp/data-hub-bundle, register OpenDxp\\Bundle\\DataHubBundle\\OpenDxpDataHubBundle in config/bundles.php, then bin/console opendxp:bundle:install OpenDxpDataHubBundle';

    /** @var \Closure(): iterable<object> */
    private readonly \Closure $loader;

    private readonly bool $dataHubEnabled;

    /**
     * @param (\Closure(): iterable<object>)|null $loader        test seam; defaults to Configuration::getList()
     * @param array<string, string>               $kernelBundles bundle name => class, to detect the Data Hub bundle
     */
    public function __construct(
        ?\Closure $loader = null,
        #[Autowire('%kernel.bundles%')] array $kernelBundles = [],
    ) {
        $this->dataHubEnabled = $loader !== null || (isset($kernelBundles[self::DATAHUB_BUNDLE]) && class_exists(self::DATAHUB_CONFIGURATION));
        $this->loader = $loader ?? static function (): iterable {
            if (!class_exists(self::DATAHUB_CONFIGURATION)) {
                return [];
            }

            return (self::DATAHUB_CONFIGURATION)::getList();
        };
    }

    public function getKey(): string
    {
        return 'datahub_graphql';
    }

    public function getLabel(): string
    {
        return 'GraphQL configurations';
    }

    public function getGroup(): string
    {
        return 'Integration';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_datahub';
    }

    public function getPermission(): string
    {
        return DatahubInstaller::PERMISSION;
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
                Field::text('name', 'Configuration', ['readOnly' => true, 'flex' => 1]),
                Field::text('type', 'Type', ['readOnly' => true, 'width' => 100]),
                Field::text('group', 'Group', ['readOnly' => true, 'width' => 140]),
                Field::bool('active', 'Active', ['readOnly' => true]),
                Field::bool('apiKeyConfigured', 'API key set', ['readOnly' => true]),
                Field::text('endpoint', 'Endpoint', ['readOnly' => true, 'flex' => 2]),
                Field::text('description', 'Description', ['readOnly' => true, 'grid' => false]),
            ],
            'actions' => [
                Action::global('status', 'Data Hub status', ['iconCls' => 'opendxp_icon_info']),
            ],
        ];
    }

    public function isDataHubEnabled(): bool
    {
        return $this->dataHubEnabled;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action !== 'status') {
            return parent::runAction($action, $id, $params);
        }

        return $this->dataHubEnabled
            ? Action::message('The OpenDXP Data Hub bundle is enabled. Create and edit GraphQL configurations in the main menu "Datahub".')
            : Action::text("The OpenDXP Data Hub bundle (GraphQL) is not enabled.\n\nTo enable it:\n  ".str_replace(', ', "\n  ", self::INSTALL_HINT)."\n\nThe Elevate DXP REST endpoints work without it.", 'Data Hub status');
    }

    public function list(array $query): array
    {
        return $this->paginate($this->rows(), $query, ['name', 'type', 'group']);
    }

    public function get(string $id): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['name'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        if (!$this->dataHubEnabled) {
            return [[
                'name' => 'Data Hub bundle not enabled',
                'type' => '-',
                'group' => '',
                'active' => false,
                'apiKeyConfigured' => false,
                'endpoint' => self::INSTALL_HINT,
                'description' => 'Install open-dxp/data-hub-bundle to create GraphQL APIs; see the "Data Hub status" action.',
            ]];
        }
        $rows = [];
        foreach (($this->loader)() as $config) {
            $rows[] = self::toRow($config);
        }

        return $rows;
    }

    /**
     * Maps an OpenDxp\Bundle\DataHubBundle\Configuration (duck-typed) to a grid row.
     *
     * @return array{name: string, type: string, group: string, active: bool, apiKeyConfigured: bool, endpoint: string, description: string}
     */
    public static function toRow(object $config): array
    {
        $name = method_exists($config, 'getName') ? (string) $config->getName() : '';
        $type = method_exists($config, 'getType') ? (string) $config->getType() : '';
        $raw = method_exists($config, 'getConfiguration') ? (array) ($config->getConfiguration() ?? []) : [];
        $security = method_exists($config, 'getSecurityConfig') ? (array) $config->getSecurityConfig() : (array) ($raw['security'] ?? []);
        $apiKey = $security['apikey'] ?? null;

        return [
            'name' => $name,
            'type' => $type,
            'group' => method_exists($config, 'getGroup') ? (string) ($config->getGroup() ?? '') : '',
            'active' => method_exists($config, 'isActive') ? (bool) $config->isActive() : false,
            'apiKeyConfigured' => \is_array($apiKey) ? $apiKey !== [] : (\is_string($apiKey) && $apiKey !== ''),
            'endpoint' => $type === 'graphql' ? '/opendxp-graphql-webservices/'.rawurlencode($name) : '',
            'description' => (string) ($raw['general']['description'] ?? ''),
        ];
    }
}
