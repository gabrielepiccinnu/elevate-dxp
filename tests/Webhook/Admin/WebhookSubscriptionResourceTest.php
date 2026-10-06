<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Admin;

use ElevateDxp\Tests\Webhook\Webhook\Fakes;
use ElevateDxp\Tests\Webhook\Webhook\RecordingMessageBus;
use ElevateDxp\Webhook\Admin\WebhookSubscriptionResource;
use ElevateDxp\Webhook\Message\SendWebhookMessage;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @phpstan-import-type History from Fakes
 */
final class WebhookSubscriptionResourceTest extends TestCase
{
    private RecordingMessageBus $bus;
    /** @var History */
    private array $history = [];

    /** @param list<ResponseInterface|\Throwable> $responses */
    private function resource(array $responses = [], bool $enabled = true): WebhookSubscriptionResource
    {
        $registry = new SubscriptionRegistry([
            'erp' => ['active' => true, 'url' => 'http://erp/hook', 'secret' => 'topsecret', 'events' => ['object.update', 'object.add']],
            'off' => ['active' => false, 'url' => 'http://off/hook', 'events' => ['object.update']],
        ], $enabled);
        $this->bus = Fakes::bus();
        $this->history = [];

        return new WebhookSubscriptionResource($registry, new WebhookDispatcher($registry, $this->bus), Fakes::sender($responses, $this->history));
    }

    public function testSchemaIsReadOnlyWithActions(): void
    {
        $r = $this->resource();
        $schema = $r->getSchema();
        self::assertSame('Integration', $r->getGroup());
        self::assertSame('elevate_dxp_webhook', $r->getPermission());
        self::assertFalse($schema['canCreate']);
        self::assertFalse($schema['canEdit']);
        self::assertFalse($schema['canDelete']);
        self::assertSame(['send_test', 'dispatch_test'], array_column($schema['actions'], 'name'));
    }

    public function testListNeverExposesSecrets(): void
    {
        $list = $this->resource()->list([]);
        self::assertSame(2, $list['total']);
        self::assertStringNotContainsString('topsecret', json_encode($list));
        self::assertSame('object.update, object.add', $list['data'][0]['events']);
        self::assertTrue($list['data'][0]['signed']);
        self::assertSame(1, $this->resource()->list(['q' => 'erp'])['total']);
        self::assertNull($this->resource()->get('nope'));
        self::assertSame('erp', $this->resource()->get('erp')['name']);
    }

    public function testSendTestSync(): void
    {
        $r = $this->resource([new Response(204)]);
        $result = $r->runAction('send_test', 'erp', ['event' => 'object.add', 'mode' => 'sync']);
        self::assertStringContainsString('delivered (HTTP 204)', $result['message']);
        self::assertCount(1, $this->history);
        self::assertStringContainsString('"test":true', (string) $this->history[0]['request']->getBody());
    }

    public function testSendTestAsyncQueues(): void
    {
        $r = $this->resource();
        $result = $r->runAction('send_test', 'erp', ['event' => 'object.update', 'mode' => 'async']);
        self::assertStringContainsString('queued', $result['message']);
        self::assertInstanceOf(SendWebhookMessage::class, $this->bus->messages[0]);
        self::assertCount(0, $this->history);
    }

    public function testSendTestAsyncRefusesInactive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resource()->runAction('send_test', 'off', ['event' => 'object.update', 'mode' => 'async']);
    }

    public function testSendTestValidatesInput(): void
    {
        $r = $this->resource();
        try {
            $r->runAction('send_test', 'erp', ['event' => 'object.explode']);
            self::fail('expected exception');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $r->runAction('send_test', 'missing', ['event' => 'object.update']);
    }

    public function testDispatchTestToAllMatching(): void
    {
        $result = $this->resource()->runAction('dispatch_test', null, ['event' => 'object.update']);
        self::assertSame('Dispatched "object.update" to 1 subscription(s).', $result['message']);
        self::assertCount(1, $this->bus->messages);
    }

    public function testDispatchTestWhenDisabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resource([], false)->runAction('dispatch_test', null, ['event' => 'object.update']);
    }
}
