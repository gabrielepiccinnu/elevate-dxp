<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Provisioning;

use ElevateDxp\Sso\Identity\IdentityDescriptor;

/**
 * What a mapped identity translates to in OpenDXP: the admin flag and the role names to assign.
 * The special values "admin", "ROLE_OPENDXP_ADMIN" (and legacy "ROLE_PIMCORE_ADMIN") set the admin flag.
 */
final class RolePlan
{
    public const ADMIN_ALIASES = ['admin', 'ROLE_OPENDXP_ADMIN', 'ROLE_PIMCORE_ADMIN'];

    /** @param list<string> $roleNames */
    public function __construct(
        public readonly bool $admin,
        public readonly array $roleNames,
    ) {
    }

    public static function fromIdentity(IdentityDescriptor $identity): self
    {
        $admin = false;
        $names = [];
        foreach ($identity->roles as $role) {
            if (self::isAdminAlias($role)) {
                $admin = true;
                continue;
            }
            $names[$role] = true;
        }

        return new self($admin, array_map('strval', array_keys($names)));
    }

    public static function isAdminAlias(string $role): bool
    {
        foreach (self::ADMIN_ALIASES as $alias) {
            if (strcasecmp($role, $alias) === 0) {
                return true;
            }
        }

        return false;
    }
}
