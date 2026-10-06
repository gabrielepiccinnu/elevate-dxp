<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Webhook;

use ElevateDxp\Core\Messenger\AsyncMessageInterface;
use ElevateDxp\Webhook\Exception\WebhookDeliveryFailedException;
use ElevateDxp\Webhook\Message\SendWebhookMessage;
use ElevateDxp\Webhook\MessageHandler\SendWebhookHandler;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use ElevateDxp\Webhook\Webhook\WebhookSignature;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class WebhookTest extends TestCase
{
    public function testSenderSignsPayloadWithHmac(): void
    {
        $history = [];
        $sender = Fakes::sender([new Response(204)], $history);
        $ok = $sender->send('local', ['url' => 'http://nginx/sink', 'secret' => 'topsecret'], 'object.update', ['id' => 7]);

        self::assertTrue($ok);
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        $body = (string) $request->getBody();
        self::assertSame('sha256='.hash_hmac('sha256', $body, 'topsecret'), $request->getHeaderLine(WebhookSignature::HEADER_SIGNATURE));
        self::assertTrue(WebhookSignature::verify($body, 'topsecret', $request->getHeaderLine(WebhookSignature::HEADER_SIGNATURE)));
        self::assertSame('object.update', $request->getHeaderLine(WebhookSignature::HEADER_EVENT));
        self::assertStringContainsString('"event":"object.update"', $body);
        self::assertSame(['event' => 'object.update', 'data' => ['id' => 7]], json_decode($body, true));
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function testSenderOmitsSignatureWithoutSecret(): void
    {
        $history = [];
        Fakes::sender([new Response(200)], $history)->send('local', ['url' => 'http://x'], 'object.add', []);
        self::assertFalse($history[0]['request']->hasHeader(WebhookSignature::HEADER_SIGNATURE));
    }

    public function testSenderReturnsFalseOnHttpError(): void
    {
        $recorder = Fakes::recorder();
        $history = [];
        $sender = Fakes::sender([new Response(500, [], 'err')], $history, $recorder);
        self::assertFalse($sender->send('local', ['url' => 'http://x', 'secret' => 's'], 'object.update', ['id' => 1]));
        self::assertCount(1, $recorder->rows);
        self::assertFalse($recorder->rows[0]['success']);
        self::assertSame(500, $recorder->rows[0]['httpStatus']);
        self::assertSame('HTTP 500', $recorder->rows[0]['error']);
    }

    public function testSenderRecordsTransportFailure(): void
    {
        $recorder = Fakes::recorder();
        $history = [];
        $sender = Fakes::sender([new ConnectException('Connection refused', new Request('POST', 'http://x'))], $history, $recorder);
        $result = $sender->deliver('local', ['url' => 'http://x'], 'asset.add', []);
        self::assertFalse($result->success);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('Connection refused', (string) $recorder->rows[0]['error']);
    }

    public function testSenderRejectsNonHttpUrl(): void
    {
        $history = [];
        $recorder = Fakes::recorder();
        $result = Fakes::sender([new Response(200)], $history, $recorder)->deliver('local', ['url' => 'file:///etc/passwd'], 'object.update', []);
        self::assertFalse($result->success);
        self::assertCount(0, $history);
        self::assertCount(1, $recorder->rows);
    }

    public function testDispatcherRoutesOnlyMatchingActiveSubscriptions(): void
    {
        $bus = Fakes::bus();
        $dispatcher = new WebhookDispatcher(new SubscriptionRegistry([
            'a' => ['active' => true, 'url' => 'http://a', 'events' => ['object.update']],
            'b' => ['active' => false, 'url' => 'http://b', 'events' => ['object.update']], // inactive
            'c' => ['active' => true, 'url' => 'http://c', 'events' => ['asset.add']],      // other event
        ]), $bus);

        self::assertSame(1, $dispatcher->dispatch('object.update', ['id' => 1]));
        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0];
        self::assertInstanceOf(SendWebhookMessage::class, $message);
        self::assertInstanceOf(AsyncMessageInterface::class, $message);
        self::assertSame('a', $message->subscriptionName);
    }

    public function testDispatcherDisabled(): void
    {
        $bus = Fakes::bus();
        $dispatcher = new WebhookDispatcher(new SubscriptionRegistry(['a' => ['active' => true, 'url' => 'http://a', 'events' => ['object.update']]], false), $bus);
        self::assertSame(0, $dispatcher->dispatch('object.update', []));
        self::assertCount(0, $bus->messages);
    }

    public function testDispatcherKeepsGoingWhenInlineDeliveryFails(): void
    {
        $bus = Fakes::bus(new \RuntimeException('sync delivery failed'));
        $dispatcher = new WebhookDispatcher(new SubscriptionRegistry([
            'a' => ['url' => 'http://a', 'events' => ['object.update']],
            'b' => ['url' => 'http://b', 'events' => ['object.update']],
        ]), $bus);
        self::assertSame(2, $dispatcher->dispatch('object.update', []));
        self::assertCount(2, $bus->messages);
    }

    public function testDispatchToUnknownSubscriptionIsRejected(): void
    {
        $dispatcher = new WebhookDispatcher(new SubscriptionRegistry([]), Fakes::bus());
        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->dispatchTo('nope', 'object.update', []);
    }

    public function testMessageDoesNotCarryTheSecret(): void
    {
        $message = new SendWebhookMessage('local', 'object.update', ['id' => 1]);
        self::assertStringNotContainsString('topsecret', serialize($message));
        self::assertSame(['subscriptionName', 'event', 'payload'], array_keys(get_object_vars($message)));
    }

    private function registry(): SubscriptionRegistry
    {
        return new SubscriptionRegistry([
            'local' => ['url' => 'http://x', 'secret' => 's', 'events' => ['object.update']],
            'off' => ['active' => false, 'url' => 'http://x', 'events' => ['object.update']],
        ]);
    }

    public function testHandlerThrowsOnFailureToTriggerRetry(): void
    {
        $handler = new SendWebhookHandler(Fakes::sender([new Response(500, [], 'err')]), $this->registry());

        try {
            $handler(new SendWebhookMessage('local', 'object.update', []));
            self::fail('Expected a delivery failure.');
        } catch (WebhookDeliveryFailedException $e) {
            // must go through the bounded retry strategy, not the unconditional "recoverable" path
            self::assertFalse((new \ReflectionClass($e))->implementsInterface(RecoverableExceptionInterface::class));
        }
    }

    public function testHandlerSucceedsSilentlyOn2xx(): void
    {
        $history = [];
        $handler = new SendWebhookHandler(Fakes::sender([new Response(204)], $history), $this->registry());
        $handler(new SendWebhookMessage('local', 'object.update', []));
        self::assertCount(1, $history);
        self::assertNotSame('', $history[0]['request']->getHeaderLine(WebhookSignature::HEADER_SIGNATURE));
    }

    public function testHandlerDropsMessageForRemovedSubscription(): void
    {
        $handler = new SendWebhookHandler(Fakes::sender([]), $this->registry());
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $handler(new SendWebhookMessage('gone', 'object.update', []));
    }

    public function testHandlerSkipsInactiveSubscription(): void
    {
        $history = [];
        $handler = new SendWebhookHandler(Fakes::sender([], $history), $this->registry());
        $handler(new SendWebhookMessage('off', 'object.update', []));
        self::assertCount(0, $history);
    }

    public function testRegistryDescribeNeverExposesSecret(): void
    {
        $d = $this->registry()->describe('local');
        self::assertNotNull($d);
        self::assertArrayNotHasKey('secret', $d);
        self::assertTrue($d['signed']);
        self::assertFalse($this->registry()->describe('off')['signed']);
        self::assertNull($this->registry()->describe('nope'));
    }
}
