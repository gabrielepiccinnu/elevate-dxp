<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Admin;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Admin\AbstractDbalResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Webhook\Installer\WebhookInstaller;
use ElevateDxp\Webhook\Repository\WebhookDeliveryRepository;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use ElevateDxp\Webhook\Webhook\WebhookEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Read-only delivery log (edxp_webhook_delivery) with one-click re-delivery.
 *
 * Filtering: the "filters" request parameter (status, subscription, event) is honoured, and the
 * free-text search box understands "status:failed", "subscription:erp", "event:object.update"
 * as well as the bare words "failed" / "delivered".
 */
final class WebhookDeliveryResource extends AbstractDbalResource
{
    private const TOKEN_FILTERS = ['status', 'subscription', 'event'];

    public function __construct(
        Connection $db,
        private readonly WebhookDispatcher $dispatcher,
        private readonly SubscriptionRegistry $subscriptions,
    ) {
        parent::__construct($db);
    }

    public function getKey(): string
    {
        return 'webhook_deliveries';
    }

    public function getLabel(): string
    {
        return 'Webhook deliveries';
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

    protected function getTable(): string
    {
        return WebhookDeliveryRepository::TABLE;
    }

    protected function getSearchColumns(): array
    {
        return ['subscription', 'event', 'url', 'error'];
    }

    public function getSchema(): array
    {
        $status = [WebhookDeliveryRepository::STATUS_DELIVERED => 'Delivered', WebhookDeliveryRepository::STATUS_FAILED => 'Failed'];
        $subscriptions = array_combine($this->subscriptions->names(), $this->subscriptions->names());

        return [
            'panel' => 'crud',
            'idProperty' => 'id',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::id(),
                Field::datetime('created_at', 'When', ['readOnly' => true, 'width' => 150]),
                Field::text('subscription', 'Subscription', ['readOnly' => true]),
                Field::text('event', 'Event', ['readOnly' => true]),
                Field::select('status', 'Status', $status, ['readOnly' => true, 'width' => 90]),
                Field::number('http_status', 'HTTP', ['readOnly' => true, 'width' => 70]),
                Field::text('url', 'URL', ['readOnly' => true, 'flex' => 1]),
                Field::text('error', 'Error', ['readOnly' => true, 'flex' => 1]),
                Field::code('payload_json', 'Payload', ['readOnly' => true]),
            ],
            'filters' => [
                Field::select('status', 'Status', $status),
                Field::select('subscription', 'Subscription', $subscriptions),
                Field::select('event', 'Event', WebhookEvents::options()),
            ],
            'actions' => [
                Action::record('redeliver', 'Re-deliver', [
                    'iconCls' => 'opendxp_icon_reload',
                    'confirm' => 'Re-send this event and payload with the current subscription configuration?',
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        return parent::list(self::extractTokenFilters($query));
    }

    /**
     * Moves "key:value" tokens (and the bare words "failed"/"delivered") from the free-text search
     * into structured filters. Pure, so it is unit-tested.
     */
    public static function extractTokenFilters(array $query): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        if ($q === '') {
            return $query;
        }
        $filters = (array) ($query['filters'] ?? []);
        $rest = [];
        foreach (preg_split('/\s+/', $q) ?: [] as $token) {
            $lower = strtolower($token);
            if (\in_array($lower, [WebhookDeliveryRepository::STATUS_FAILED, WebhookDeliveryRepository::STATUS_DELIVERED], true)) {
                $filters['status'] = $lower;
            } elseif (preg_match('/^([a-z]+):(.+)$/i', $token, $m) && \in_array(strtolower($m[1]), self::TOKEN_FILTERS, true)) {
                $filters[strtolower($m[1])] = strtolower($m[1]) === 'status' ? strtolower($m[2]) : $m[2];
            } else {
                $rest[] = $token;
            }
        }
        $query['q'] = implode(' ', $rest);
        $query['filters'] = $filters;

        return $query;
    }

    protected function decorate(array $row): array
    {
        if (isset($row['payload_json']) && \is_string($row['payload_json'])) {
            $decoded = json_decode($row['payload_json'], true);
            if (\is_array($decoded)) {
                $row['payload_json'] = (string) json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
        }
        if (\array_key_exists('success', $row)) {
            $row['success'] = (bool) $row['success'];
        }

        return $row;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action !== 'redeliver') {
            return parent::runAction($action, $id, $params);
        }
        if ($id === null || !ctype_digit($id)) {
            throw new \InvalidArgumentException('Select a delivery to re-deliver.');
        }
        $row = $this->get($id);
        if ($row === null) {
            throw new \InvalidArgumentException(\sprintf('Delivery #%s not found.', $id));
        }
        $name = (string) $row['subscription'];
        if ($this->subscriptions->get($name) === null) {
            throw new \InvalidArgumentException(\sprintf('Subscription "%s" no longer exists in configuration.', $name));
        }
        if (!$this->subscriptions->isActive($name)) {
            throw new \InvalidArgumentException(\sprintf('Subscription "%s" is inactive.', $name));
        }

        try {
            $this->dispatcher->dispatchTo($name, (string) $row['event'], WebhookDeliveryRepository::payloadOf($row));
        } catch (\Throwable $e) {
            // sync:// transport: the delivery ran inline and failed; the attempt is in the log.
            $cause = $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;

            return Action::message(\sprintf('Re-delivery of #%s failed: %s', $id, $cause->getMessage()), true);
        }

        return Action::message(\sprintf('Re-delivery of #%s queued.', $id), true);
    }
}
