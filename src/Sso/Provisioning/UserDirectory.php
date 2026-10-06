<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Provisioning;

use OpenDxp\Model\User;
use OpenDxp\Model\User\Role;

/** Thin lookup over OpenDXP users/roles (isolated so admin previews can be unit-tested). */
class UserDirectory
{
    public function roleId(string $name): ?int
    {
        $role = Role::getByName($name);

        return $role instanceof Role ? (int) $role->getId() : null;
    }

    public function findUser(string $name): ?User
    {
        $user = User::getByName($name);

        return $user instanceof User ? $user : null;
    }
}
