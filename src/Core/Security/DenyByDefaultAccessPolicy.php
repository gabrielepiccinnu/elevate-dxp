<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Security;

use ElevateDxp\Core\Contract\AccessPolicyInterface;

final class DenyByDefaultAccessPolicy implements AccessPolicyInterface
{
    /**
     * @param list<string> $allowedActions
     */
    public function __construct(
        private readonly string $defaultPolicy = 'deny',
        private readonly array $allowedActions = [],
    ) {
    }

    /** @param array<string, mixed> $context */
    public function isAllowed(string $action, array $context = []): bool
    {
        return $this->defaultPolicy === 'allow' || \in_array($action, $this->allowedActions, true);
    }
}
