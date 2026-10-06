<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Dto;

final class AuditEvent
{
    public function __construct(
        public readonly string $action,
        public readonly string $actor,
        public readonly string $status,
        public readonly array $context = [],
    ) {
    }
}
