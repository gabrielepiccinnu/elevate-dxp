<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Webhook;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Message bus that records dispatched messages; optionally throws to emulate a failing sync:// delivery. */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function __construct(private readonly ?\Throwable $throw = null)
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
}
