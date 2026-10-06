<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Exception;

/**
 * Thrown by the message handler when a delivery fails, so Messenger applies the transport retry
 * strategy (max_retries, delay, multiplier) and then the failure transport.
 *
 * Deliberately NOT a RecoverableExceptionInterface: Messenger retries those unconditionally,
 * which would retry a dead endpoint forever.
 */
final class WebhookDeliveryFailedException extends \RuntimeException
{
}
