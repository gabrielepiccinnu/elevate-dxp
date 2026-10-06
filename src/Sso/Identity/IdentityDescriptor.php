<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Identity;

/** Result of mapping OIDC claims to a local identity. Empty roles (or identifier) ⇒ access must be denied. */
final class IdentityDescriptor
{
    /** @param list<string> $roles */
    public function __construct(
        public readonly string $identifier,
        public readonly ?string $email,
        public readonly array $roles,
    ) {
    }

    public function isAuthorized(): bool
    {
        return $this->identifier !== '' && $this->roles !== [];
    }
}
