<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\MessageHandler;

use ElevateDxp\Webhook\Exception\WebhookDeliveryFailedException;
use ElevateDxp\Webhook\Message\SendWebhookMessage;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\WebhookSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Delivers a queued webhook. On failure it THROWS so Messenger retries per the "elevate_dxp" transport's
 * retry strategy, bounded by max_retries (the synchronous sender swallows errors and returns false;
 * here that becomes a retryable failure). A subscription removed from configuration is unrecoverable (no retry);
 * a deactivated one is skipped.
 */
#[AsMessageHandler]
final class SendWebhookHandler
{
    public function __construct(
        private readonly WebhookSender $sender,
        private readonly SubscriptionRegistry $subscriptions,
    ) {
    }

    public function __invoke(SendWebhookMessage $message): void
    {
        $subscription = $this->subscriptions->get($message->subscriptionName);
        if ($subscription === null) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Webhook subscription "%s" no longer exists; message dropped.', $message->subscriptionName));
        }
        if (!$this->subscriptions->isActive($message->subscriptionName)) {
            return;
        }

        $ok = $this->sender->send($message->subscriptionName, $subscription, $message->event, $message->payload);
        if (!$ok) {
            throw new WebhookDeliveryFailedException(\sprintf('Webhook "%s" (event %s) delivery failed; will retry.', $message->subscriptionName, $message->event));
        }
    }
}
