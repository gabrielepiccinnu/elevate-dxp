<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Identity;

interface IdentityMapperInterface
{
    /** @param array<string,mixed> $claims validated ID-token claims */
    public function mapClaims(array $claims): IdentityDescriptor;
}
