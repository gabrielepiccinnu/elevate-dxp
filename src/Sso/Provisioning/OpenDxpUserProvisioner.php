<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Provisioning;

use ElevateDxp\Sso\Identity\IdentityDescriptor;
use OpenDxp\Model\User;

/**
 * Upserts a NATIVE OpenDXP user from an SSO identity and syncs its admin flag and roles, so the
 * admin UI, the portal and SSO share one identity/role model.
 *
 * Deny-by-default: an identity without roles (or identifier) is refused; mapped role names that do
 * not exist in OpenDXP are ignored (never auto-created), and an identity whose roles ALL do not
 * exist (and that is not admin) is refused before any user is created or changed.
 */
class OpenDxpUserProvisioner
{
    public function __construct(private readonly UserDirectory $directory)
    {
    }

    public function provision(IdentityDescriptor $identity): User
    {
        if (!$identity->isAuthorized()) {
            throw new \RuntimeException('Refusing to provision an unauthorized identity (no roles mapped).');
        }

        $plan = RolePlan::fromIdentity($identity);
        $roleIds = [];
        foreach ($plan->roleNames as $roleName) {
            $id = $this->directory->roleId($roleName);
            if ($id !== null) {
                $roleIds[] = $id;
            }
        }
        if (!$plan->admin && $roleIds === []) {
            throw new \RuntimeException('Refusing to provision an identity whose mapped roles do not exist in OpenDXP.');
        }

        $user = $this->directory->findUser($identity->identifier);
        if ($user === null) {
            $user = User::create([
                'parentId' => 0,
                'name' => $identity->identifier,
                'active' => true,
            ]);
        }

        if ($identity->email !== null && $identity->email !== '') {
            $user->setEmail($identity->email);
        }
        $user->setActive(true);

        $user->setAdmin($plan->admin);
        $user->setRoles($roleIds);
        $user->save();

        return $user;
    }
}
