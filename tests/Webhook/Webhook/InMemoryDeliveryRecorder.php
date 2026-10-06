<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Webhook;

use ElevateDxp\Webhook\Contract\DeliveryRecorderInterface;

/** Delivery recorder that keeps every recorded attempt in memory. */
final class InMemoryDeliveryRecorder implements DeliveryRecorderInterface
{
    /**
     * @var list<array{subscription: string, event: string, url: string, success: bool, httpStatus: ?int, error: ?string, payload: array<string, mixed>}>
     */
    public array $rows = [];

    public function record(string $subscription, string $event, string $url, bool $success, ?int $httpStatus, ?string $error, array $payload): void
    {
        $this->rows[] = [
            'subscription' => $subscription,
            'event' => $event,
            'url' => $url,
            'success' => $success,
            'httpStatus' => $httpStatus,
            'error' => $error,
            'payload' => $payload,
        ];
    }
}
