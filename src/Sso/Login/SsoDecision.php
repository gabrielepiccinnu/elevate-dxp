<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Login;

use ElevateDxp\Sso\Identity\IdentityDescriptor;

final class SsoDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly IdentityDescriptor $identity,
        public readonly string $reason,
    ) {
    }

    public static function allow(IdentityDescriptor $identity, string $reason): self
    {
        return new self(true, $identity, $reason);
    }

    public static function deny(IdentityDescriptor $identity, string $reason): self
    {
        return new self(false, $identity, $reason);
    }
}
