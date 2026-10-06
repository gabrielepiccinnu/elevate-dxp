<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Contract;

/** Records a single webhook delivery attempt (for history / re-delivery). */
interface DeliveryRecorderInterface
{
    /** @param array<string,mixed> $payload */
    public function record(
        string $subscription,
        string $event,
        string $url,
        bool $success,
        ?int $httpStatus,
        ?string $error,
        array $payload,
    ): void;
}
