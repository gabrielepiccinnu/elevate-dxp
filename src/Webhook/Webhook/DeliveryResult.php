<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Webhook;

/** Outcome of one delivery attempt. */
final class DeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $httpStatus = null,
        public readonly ?string $error = null,
    ) {
    }

    public function describe(): string
    {
        if ($this->success) {
            return 'delivered (HTTP '.$this->httpStatus.')';
        }

        return 'failed: '.($this->error ?? ($this->httpStatus !== null ? 'HTTP '.$this->httpStatus : 'unknown error'));
    }
}
