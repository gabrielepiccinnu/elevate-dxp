<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Webhook\Installer\WebhookInstaller;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\TestPayload;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use ElevateDxp\Webhook\Webhook\WebhookEvents;
use ElevateDxp\Webhook\Webhook\WebhookSender;

/**
 * Config-backed, read-only list of webhook subscriptions (edit them in YAML under
 * elevate_dxp_webhook.subscriptions) with "send test" actions. Secrets are never exposed.
 */
final class WebhookSubscriptionResource extends AbstractAdminResource
{
    public function __construct(
        private readonly SubscriptionRegistry $subscriptions,
        private readonly WebhookDispatcher $dispatcher,
        private readonly WebhookSender $sender,
    ) {
    }

    public function getKey(): string
    {
        return 'webhook_subscriptions';
    }

    public function getLabel(): string
    {
        return 'Webhook subscriptions';
    }

    public function getGroup(): string
    {
        return 'Integration';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_webhook';
    }

    public function getPermission(): string
    {
        return WebhookInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        $eventParam = Field::select('event', 'Event', WebhookEvents::options(), ['required' => true, 'default' => WebhookEvents::OBJECT_UPDATE]);

        return [
            'panel' => 'crud',
            'idProperty' => 'name',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::text('name', 'Name', ['readOnly' => true, 'flex' => 1]),
                Field::bool('active', 'Active', ['readOnly' => true]),
                Field::text('url', 'URL', ['readOnly' => true, 'flex' => 2]),
                Field::text('events', 'Events', ['readOnly' => true, 'flex' => 2]),
                Field::bool('signed', 'HMAC signed', ['readOnly' => true, 'help' => 'A signing secret is configured (the secret itself is never shown).']),
            ],
            'actions' => [
                Action::record('send_test', 'Send test', [
                    'iconCls' => 'opendxp_icon_email',
                    'params' => [
                        $eventParam,
                        Field::select('mode', 'Delivery', ['sync' => 'Now (synchronous, shows the result)', 'async' => 'Queue (Messenger, with retry)'], ['required' => true, 'default' => 'sync']),
                    ],
                ]),
                Action::global('dispatch_test', 'Dispatch test event to all', [
                    'params' => [$eventParam],
                    'confirm' => 'Send a test event to every active subscription listening to it?',
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        $rows = array_map(fn (string $name): array => $this->row($name), $this->subscriptions->names());

        return $this->paginate($rows, $query, ['name', 'url', 'events']);
    }

    public function get(string $id): ?array
    {
        return $this->subscriptions->get($id) === null ? null : $this->row($id);
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        return match ($action) {
            'send_test' => $this->sendTest($id, $params),
            'dispatch_test' => $this->dispatchTest($params),
            default => parent::runAction($action, $id, $params),
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function sendTest(?string $name, array $params): array
    {
        $subscription = $name !== null ? $this->subscriptions->get($name) : null;
        if ($name === null || $subscription === null) {
            throw new \InvalidArgumentException('Select a configured subscription.');
        }
        $event = $this->event($params);
        $payload = TestPayload::create($event, 'admin');

        if (($params['mode'] ?? 'sync') === 'async') {
            if (!$this->subscriptions->isActive($name)) {
                throw new \InvalidArgumentException(\sprintf('Subscription "%s" is inactive: queued deliveries are skipped. Use synchronous mode to test it.', $name));
            }
            $this->dispatcher->dispatchTo($name, $event, $payload);

            return Action::message(\sprintf('Test "%s" queued for "%s". See the delivery log.', $event, $name));
        }

        $result = $this->sender->deliver($name, $subscription, $event, $payload);

        return Action::message(\sprintf('Test "%s" to "%s": %s', $event, $name, $result->describe()));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function dispatchTest(array $params): array
    {
        if (!$this->subscriptions->isEnabled()) {
            throw new \InvalidArgumentException('Webhooks are disabled (elevate_dxp_webhook.enabled: false).');
        }
        $event = $this->event($params);
        $count = $this->dispatcher->dispatch($event, TestPayload::create($event, 'admin'));

        return Action::message(\sprintf('Dispatched "%s" to %d subscription(s).', $event, $count));
    }

    /** @param array<string, mixed> $params */
    private function event(array $params): string
    {
        $event = (string) ($params['event'] ?? WebhookEvents::OBJECT_UPDATE);
        if (!\in_array($event, WebhookEvents::ALL, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown event "%s".', $event));
        }

        return $event;
    }

    /** @return array<string, mixed> */
    private function row(string $name): array
    {
        $d = $this->subscriptions->describe($name) ?? [];
        $d['events'] = implode(', ', $d['events'] ?? []);

        return $d;
    }
}
