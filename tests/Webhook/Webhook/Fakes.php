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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Small test doubles shared by the webhook tests. */
final class Fakes
{
    public static function audit(): AuditLoggerInterface
    {
        return new class implements AuditLoggerInterface {
            public array $events = [];

            public function log(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    public static function recorder(): DeliveryRecorderInterface
    {
        return new class implements DeliveryRecorderInterface {
            /** @var list<array<string,mixed>> */
            public array $rows = [];

            public function record(string $subscription, string $event, string $url, bool $success, ?int $httpStatus, ?string $error, array $payload): void
            {
                $this->rows[] = compact('subscription', 'event', 'url', 'success', 'httpStatus', 'error', 'payload');
            }
        };
    }

    /**
     * @param list<mixed>                    $responses Guzzle responses or exceptions
     * @param array<int,array<string,mixed>> $history   filled with the sent requests
     */
    public static function client(array $responses, array &$history = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    public static function sender(array $responses, array &$history = [], ?DeliveryRecorderInterface $recorder = null): WebhookSender
    {
        return new WebhookSender(self::client($responses, $history), self::audit(), $recorder ?? self::recorder(), 5);
    }

    /** Bus recording dispatched messages; optionally throws to emulate a failing sync:// delivery. */
    public static function bus(?\Throwable $throw = null): MessageBusInterface
    {
        return new class($throw) implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function __construct(private readonly ?\Throwable $throw)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return new Envelope($message);
            }
        };
    }
}
