<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Webhook;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Webhook\Contract\DeliveryRecorderInterface;
use ElevateDxp\Webhook\Webhook\WebhookSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Small test doubles shared by the webhook tests.
 *
 * `$history` arrays receive one entry per sent request, holding the request and its Guzzle options.
 *
 * @phpstan-type History list<array{request: RequestInterface, options: array<mixed>}>
 */
final class Fakes
{
    public static function audit(): AuditLoggerInterface
    {
        return new class implements AuditLoggerInterface {
            /** @var list<AuditEvent> */
            public array $events = [];

            public function log(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    public static function recorder(): InMemoryDeliveryRecorder
    {
        return new InMemoryDeliveryRecorder();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $responses queued Guzzle responses or exceptions
     * @param History                            $history   filled with the sent requests
     *
     * @param-out History $history
     */
    public static function client(array $responses, array &$history = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(static function (RequestInterface $request, array $options) use (&$history): void {
            $history[] = ['request' => $request, 'options' => $options];
        }));

        return new Client(['handler' => $stack]);
    }

    /**
     * @param list<ResponseInterface|\Throwable> $responses queued Guzzle responses or exceptions
     * @param History                            $history   filled with the sent requests
     *
     * @param-out History $history
     */
    public static function sender(array $responses, array &$history = [], ?DeliveryRecorderInterface $recorder = null): WebhookSender
    {
        return new WebhookSender(self::client($responses, $history), self::audit(), $recorder ?? self::recorder(), 5);
    }

    /** Bus recording dispatched messages; optionally throws to emulate a failing sync:// delivery. */
    public static function bus(?\Throwable $throw = null, bool $handled = false): RecordingMessageBus
    {
        return new RecordingMessageBus($throw, $handled);
    }
}
