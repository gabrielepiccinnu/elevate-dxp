<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Admin;

use Doctrine\DBAL\Connection;
use ElevateDxp\Tests\Webhook\Webhook\Fakes;
use ElevateDxp\Tests\Webhook\Webhook\RecordingMessageBus;
use ElevateDxp\Webhook\Admin\WebhookDeliveryResource;
use ElevateDxp\Webhook\Message\SendWebhookMessage;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class WebhookDeliveryResourceTest extends TestCase
{
    private RecordingMessageBus $bus;

    /** @param array<string, mixed>|false $row */
    private function resource(array|false $row, ?\Throwable $busFailure = null): WebhookDeliveryResource
    {
        $db = $this->createStub(Connection::class);
        $db->method('quoteIdentifier')->willReturnCallback(static fn (string $s): string => '`'.$s.'`');
        $db->method('fetchAssociative')->willReturn($row);
        $registry = new SubscriptionRegistry([
            'erp' => ['url' => 'http://erp', 'secret' => 's', 'events' => ['object.update']],
            'off' => ['active' => false, 'url' => 'http://off', 'events' => ['object.update']],
        ]);
        $this->bus = Fakes::bus($busFailure);

        return new WebhookDeliveryResource($db, new WebhookDispatcher($registry, $this->bus), $registry);
    }

    /** @return array<string, mixed> */
    private function row(string $subscription = 'erp'): array
    {
        return ['id' => 5, 'subscription' => $subscription, 'event' => 'object.update', 'url' => 'http://erp', 'status' => 'failed',
            'success' => 0, 'http_status' => 500, 'error' => 'HTTP 500', 'payload_json' => '{"id":42}', 'created_at' => '2026-01-01 10:00:00'];
    }

    public function testSchema(): void
    {
        $schema = $this->resource(false)->getSchema();
        self::assertFalse($schema['canCreate']);
        self::assertFalse($schema['canEdit']);
        self::assertFalse($schema['canDelete']);
        self::assertSame(['redeliver'], array_column($schema['actions'], 'name'));
        self::assertSame('record', $schema['actions'][0]['scope']);
        self::assertSame(['status', 'subscription', 'event'], array_column($schema['filters'], 'name'));
    }

    public function testTokenFilters(): void
    {
        $q = WebhookDeliveryResource::extractTokenFilters(['q' => 'failed erp-host subscription:erp event:object.update']);
        self::assertSame('erp-host', $q['q']);
        self::assertSame(['status' => 'failed', 'subscription' => 'erp', 'event' => 'object.update'], $q['filters']);

        $q = WebhookDeliveryResource::extractTokenFilters(['q' => 'STATUS:Delivered', 'filters' => ['event' => 'asset.add']]);
        self::assertSame(['event' => 'asset.add', 'status' => 'delivered'], $q['filters']);
        self::assertSame('', $q['q']);

        self::assertSame(['q' => ''], WebhookDeliveryResource::extractTokenFilters(['q' => '']));
        self::assertSame('unknown:x', WebhookDeliveryResource::extractTokenFilters(['q' => 'unknown:x'])['q']);
    }

    public function testGetPrettyPrintsPayload(): void
    {
        $row = $this->resource($this->row())->get('5');
        self::assertNotNull($row);
        self::assertStringContainsString("\n", $row['payload_json']);
        self::assertFalse($row['success']);
    }

    public function testRedeliverQueuesStoredPayload(): void
    {
        $result = $this->resource($this->row())->runAction('redeliver', '5', []);
        self::assertTrue($result['reload']);
        self::assertStringContainsString('queued', $result['message']);
        $m = $this->bus->messages[0];
        self::assertInstanceOf(SendWebhookMessage::class, $m);
        self::assertSame('erp', $m->subscriptionName);
        self::assertSame('object.update', $m->event);
        self::assertSame(['id' => 42], $m->payload);
    }

    public function testRedeliverReportsInlineFailure(): void
    {
        $failure = new HandlerFailedException(new Envelope(new \stdClass()), [new \RuntimeException('HTTP 500 again')]);
        $result = $this->resource($this->row(), $failure)->runAction('redeliver', '5', []);
        self::assertStringContainsString('HTTP 500 again', $result['message']);
        self::assertTrue($result['reload']);
    }

    public function testRedeliverGuards(): void
    {
        foreach ([[false, '5'], [$this->row('gone'), '5'], [$this->row('off'), '5'], [$this->row(), null], [$this->row(), 'abc']] as [$row, $id]) {
            try {
                $this->resource($row)->runAction('redeliver', $id, []);
                self::fail('expected exception');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertCount(0, $this->bus->messages);
    }

    public function testUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resource(false)->runAction('purge', null, []);
    }
}
