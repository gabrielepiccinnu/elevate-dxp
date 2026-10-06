<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Identity;

/**
 * Maps OIDC claims to a local identity with DENY-BY-DEFAULT role assignment:
 * only groups present in the configured role_mapping grant a role. Unmapped groups grant nothing,
 * preventing privilege escalation from arbitrary IdP group membership. Group matching is exact
 * (case-sensitive), so "Admins" does not match a mapping for "admins".
 */
final class RoleMappingIdentityMapper implements IdentityMapperInterface
{
    /** @param array<string,string> $roleMapping IdP group => OpenDXP role name (or "admin") */
    public function __construct(
        private readonly string $groupsClaim,
        private readonly array $roleMapping,
        private readonly string $identifierClaim = 'sub',
    ) {
    }

    public function mapClaims(array $claims): IdentityDescriptor
    {
        $identifier = self::scalar($claims[$this->identifierClaim] ?? null) ?? self::scalar($claims['sub'] ?? null) ?? self::scalar($claims['email'] ?? null) ?? '';
        $email = self::scalar($claims['email'] ?? null);

        $groups = $claims[$this->groupsClaim] ?? [];
        if (\is_string($groups)) {
            $groups = [$groups];
        }
        if (!\is_array($groups)) {
            $groups = [];
        }

        $roles = [];
        foreach ($groups as $group) {
            if (!\is_scalar($group)) {
                continue;
            }
            $key = (string) $group;
            if (isset($this->roleMapping[$key]) && $this->roleMapping[$key] !== '') {
                $roles[$this->roleMapping[$key]] = true; // dedup
            }
        }

        return new IdentityDescriptor(trim($identifier), $email, array_map('strval', array_keys($roles)));
    }

    /** @return array<string,string> */
    public function getRoleMapping(): array
    {
        return $this->roleMapping;
    }

    private static function scalar(mixed $v): ?string
    {
        return \is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }
}
