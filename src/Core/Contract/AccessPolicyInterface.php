<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Contract;

interface AccessPolicyInterface
{
    /** @param array<string, mixed> $context */
    public function isAllowed(string $action, array $context = []): bool;
}
