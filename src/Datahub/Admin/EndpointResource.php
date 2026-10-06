<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Datahub\Api\EndpointExecutor;
use ElevateDxp\Datahub\Installer\DatahubInstaller;

/**
 * Read-only admin view of the YAML-declared REST endpoints with a "Try request" action that
 * runs the endpoint exactly as the public API would (without the API key, which is never shown).
 */
final class EndpointResource extends AbstractAdminResource
{
    public function __construct(
        private readonly EndpointExecutor $executor,
        private readonly string $apiKeyHeader = 'X-Elevate-Dxp-Api-Key',
        private readonly bool $apiKeyConfigured = false,
    ) {
    }

    public function getKey(): string
    {
        return 'datahub_endpoints';
    }

    public function getLabel(): string
    {
        return 'REST endpoints';
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
                Field::text('name', 'Endpoint', ['readOnly' => true, 'flex' => 1]),
                Field::text('type', 'Type', ['readOnly' => true, 'width' => 110]),
                Field::text('class', 'Class', ['readOnly' => true, 'width' => 140]),
                Field::text('path', 'Path', ['readOnly' => true, 'flex' => 2]),
                Field::text('fieldList', 'Fields', ['readOnly' => true, 'flex' => 2]),
                Field::text('authHeader', 'Auth header', ['readOnly' => true, 'grid' => false]),
                Field::keyvalue('fields', 'Field mapping (output => accessor)', ['readOnly' => true]),
            ],
            'actions' => [
                Action::record('try', 'Try request', [
                    'iconCls' => 'opendxp_icon_preview',
                    'params' => [
                        Field::number('id', 'ID (empty = list)', ['help' => 'Fetch one element instead of a page']),
                        Field::number('page', 'Page', ['default' => 1]),
                        Field::number('limit', 'Limit', ['default' => 10, 'help' => 'Capped at '.$this->executor->maxLimit()]),
                    ],
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        return $this->paginate(array_map($this->decorate(...), $this->executor->describe()), $query, ['name', 'type', 'class', 'path']);
    }

    public function get(string $id): ?array
    {
        foreach ($this->executor->describe() as $row) {
            if ($row['name'] === $id) {
                return $this->decorate($row);
            }
        }

        return null;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action !== 'try') {
            return parent::runAction($action, $id, $params);
        }
        $endpoint = (string) $id;
        if ($endpoint === '' || !$this->executor->has($endpoint)) {
            throw new \InvalidArgumentException('Select an existing endpoint.');
        }

        $rawId = $params['id'] ?? null;
        $detailId = $rawId === null || $rawId === '' ? null : filter_var($rawId, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($detailId === false) {
            throw new \InvalidArgumentException('ID must be a positive integer.');
        }

        try {
            if ($detailId !== null) {
                $url = EndpointExecutor::publicPath($endpoint).'/'.$detailId;
                $result = $this->executor->detail($endpoint, $detailId);
            } else {
                $page = EndpointExecutor::clampPage($params['page'] ?? 1);
                $limit = $this->executor->clampLimit($params['limit'] ?? 10);
                $url = EndpointExecutor::publicPath($endpoint).'?'.http_build_query(['page' => $page, 'limit' => $limit]);
                $result = $this->executor->list($endpoint, $page, $limit);
            }
        } catch (\Throwable $e) {
            throw new \DomainException('Request failed: '.$e->getMessage(), 0, $e);
        }

        $text = \sprintf(
            "GET %s\n%s: <api-key>\n\nHTTP %d\n%s\n\n# curl -H '%s: <api-key>' 'https://<host>%s'%s\n",
            $url,
            $this->apiKeyHeader,
            $result['status'],
            json_encode($result['body'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
            $this->apiKeyHeader,
            $url,
            $this->apiKeyConfigured ? '' : "\n# WARNING: elevate_dxp.datahub.api_key is empty, the public API denies every request.",
        );

        return Action::text($text, 'Try request: '.$endpoint, 'json');
    }

    /**
     * @param array{name:string,type:string,class:string,path:string,fields:array<string,string>} $row
     *
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        return $row + [
            'fieldList' => implode(', ', array_keys($row['fields'])),
            'authHeader' => $this->apiKeyHeader,
        ];
    }
}
