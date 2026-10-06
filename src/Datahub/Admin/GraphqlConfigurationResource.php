<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Datahub\Installer\DatahubInstaller;

/**
 * Read-only list of the native OpenDXP DataHub configurations (open-dxp/data-hub-bundle),
 * the primary headless API. Empty when the DataHub bundle is not installed. Secrets (API keys)
 * are never exposed; only whether one is configured.
 */
final class GraphqlConfigurationResource extends AbstractAdminResource
{
    public const DATAHUB_CONFIGURATION = 'OpenDxp\\Bundle\\DataHubBundle\\Configuration';

    /** @var \Closure(): iterable<object> */
    private readonly \Closure $loader;

    /** @param (\Closure(): iterable<object>)|null $loader test seam; defaults to Configuration::getList() */
    public function __construct(?\Closure $loader = null)
    {
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
            'actions' => [],
        ];
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
        $rows = [];
        foreach (($this->loader)() as $config) {
            $rows[] = self::toRow($config);
        }

        return $rows;
    }

    /** Maps an OpenDxp\Bundle\DataHubBundle\Configuration (duck-typed) to a grid row. */
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
