<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Webhook;

use ElevateDxp\Webhook\Message\SendWebhookMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** Routes an event to every active subscription registered for it, dispatching async delivery messages. */
final class WebhookDispatcher
{
    /** The message was handled inline (sync:// transport or no routing) and the delivery succeeded. */
    public const OUTCOME_DELIVERED = 'delivered';
    /** The message was handed to an asynchronous transport; the outcome is not known yet. */
    public const OUTCOME_QUEUED = 'queued';

    public function __construct(
        private readonly SubscriptionRegistry $subscriptions,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return int number of delivery messages dispatched
     */
    public function dispatch(string $event, array $payload): int
    {
        if (!$this->subscriptions->isEnabled()) {
            return 0;
        }
        $count = 0;
        foreach ($this->subscriptions->names() as $name) {
            $sub = $this->subscriptions->get($name) ?? [];
            if (($sub['active'] ?? true) !== true || !\in_array($event, $sub['events'] ?? [], true)) {
                continue;
            }
            ++$count;
            try {
                $this->bus->dispatch(new SendWebhookMessage($name, $event, $payload));
            } catch (\Throwable) {
                // With the default sync:// transport the delivery runs inline and a failure surfaces here.
                // It is already recorded in the delivery log; keep going so other subscribers still get it.
            }
        }

        return $count;
    }

    /**
     * Queues (re-)delivery of one event to one named subscription.
     *
     * With the sync:// transport the delivery runs inline: a failed delivery surfaces as an exception
     * (Messenger HandlerFailedException) and a successful one returns OUTCOME_DELIVERED. With an async
     * transport the message is only queued (OUTCOME_QUEUED).
     *
     * @param array<string, mixed> $payload
     *
     * @return self::OUTCOME_* the outcome known at dispatch time
     */
    public function dispatchTo(string $subscription, string $event, array $payload): string
    {
        if ($this->subscriptions->get($subscription) === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown webhook subscription "%s".', $subscription));
        }

        return self::outcomeOf($this->bus->dispatch(new SendWebhookMessage($subscription, $event, $payload)));
    }

    /** @return self::OUTCOME_* */
    public static function outcomeOf(Envelope $envelope): string
    {
        return $envelope->last(HandledStamp::class) !== null ? self::OUTCOME_DELIVERED : self::OUTCOME_QUEUED;
    }

    /** @return list<string> */
    public function subscriptionNames(): array
    {
        return $this->subscriptions->names();
    }

    /** @return array{active?:bool,url:string,secret?:string,events?:list<string>}|null */
    public function subscription(string $name): ?array
    {
        return $this->subscriptions->get($name);
    }
}
