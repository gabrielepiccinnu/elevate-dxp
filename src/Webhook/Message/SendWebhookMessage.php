<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Message;

use ElevateDxp\Core\Messenger\AsyncMessageInterface;

/**
 * Async message: deliver one webhook to one subscription. Handled by SendWebhookHandler.
 * Routed to the "elevate_dxp" transport by the core bundle (ELEVATE_DXP_MESSENGER_DSN).
 *
 * Only the subscription NAME travels through the transport: URL and secret are resolved from the
 * current configuration at delivery time, so signing secrets are never serialised into the queue
 * and a rotated secret applies to pending retries too.
 */
final class SendWebhookMessage implements AsyncMessageInterface
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $subscriptionName,
        public readonly string $event,
        public readonly array $payload,
    ) {
    }
}
