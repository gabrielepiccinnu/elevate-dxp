<?php

declare(strict_types=1);

namespace ElevateDxp\Automation\Admin;

use ElevateDxp\Automation\Installer\AutomationInstaller;
use ElevateDxp\Automation\N8n\N8nBlueprintGenerator;
use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;

/**
 * Lists the webhook subscriptions an n8n blueprint can be generated for, with a
 * "Generate n8n blueprint" action returning the importable JSON (copy it into n8n → Import).
 */
final class N8nBlueprintResource extends AbstractAdminResource
{
    public function __construct(private readonly N8nBlueprintGenerator $generator)
    {
    }

    public function getKey(): string
    {
        return 'automation_n8n';
    }

    public function getLabel(): string
    {
        return 'Automation (n8n)';
    }

    public function getGroup(): string
    {
        return 'Integration';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_automation';
    }

    public function getPermission(): string
    {
        return AutomationInstaller::PERMISSION;
    }

    /** @return array<string, mixed> */
    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'idProperty' => 'name',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::text('name', 'Subscription', ['readOnly' => true, 'flex' => 1]),
                Field::text('events', 'Events', ['readOnly' => true, 'flex' => 2]),
                Field::text('n8n_path', 'n8n webhook path', ['readOnly' => true, 'flex' => 1]),
            ],
            'actions' => [
                Action::record('blueprint', 'Generate n8n blueprint', ['iconCls' => 'opendxp_icon_export']),
            ],
        ];
    }

    public function list(array $query): array
    {
        $rows = array_map(fn (string $n): array => $this->row($n), $this->generator->subscriptionNames());

        return $this->paginate($rows, $query, ['name', 'events']);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return \in_array($id, $this->generator->subscriptionNames(), true) ? $this->row($id) : null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action !== 'blueprint') {
            return parent::runAction($action, $id, $params);
        }
        if (!$this->generator->isEnabled()) {
            throw new \InvalidArgumentException('Automation is disabled (elevate_dxp_automation.enabled: false).');
        }
        $blueprint = $id !== null ? $this->generator->fromWebhook($id) : null;
        if ($blueprint === null) {
            throw new \InvalidArgumentException('Select a configured webhook subscription.');
        }

        return Action::text($this->generator->toJson($blueprint), \sprintf('n8n blueprint · %s (n8n → Import from clipboard)', $id), 'json');
    }

    /** @return array<string, mixed> */
    private function row(string $name): array
    {
        return [
            'name' => $name,
            'events' => implode(', ', $this->generator->eventsOf($name)),
            'n8n_path' => N8nBlueprintGenerator::webhookPath($name),
        ];
    }
}
