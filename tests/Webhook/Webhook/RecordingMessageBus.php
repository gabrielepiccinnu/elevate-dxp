<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Webhook;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** Message bus that records dispatched messages; optionally throws to emulate a failing sync:// delivery. */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @param bool $handled true emulates the sync:// transport (returned envelope carries a HandledStamp) */
    public function __construct(private readonly ?\Throwable $throw = null, private readonly bool $handled = false)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $envelope = new Envelope($message);

        return $this->handled ? $envelope->with(new HandledStamp(null, 'handler')) : $envelope;
    }
}
