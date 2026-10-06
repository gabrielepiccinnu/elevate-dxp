<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Security;

use OpenDxp\Model\User;
use OpenDxp\Security\User\TokenStorageUserResolver;

/**
 * Resolves the logged-in OpenDXP user acting on portal data. Collections and saved views are
 * scoped to the owner (user name); admins may see everything.
 */
class CurrentOwner
{
    public function __construct(private readonly TokenStorageUserResolver $resolver)
    {
    }

    public function user(): ?User
    {
        return $this->resolver->getUser();
    }

    public function name(): string
    {
        $user = $this->user();
        if ($user === null || (string) $user->getName() === '') {
            throw new \DomainException('No authenticated user.');
        }

        return (string) $user->getName();
    }

    /** Owner filter for queries: null means "all owners" (admins). */
    public function scope(): ?string
    {
        return $this->user()?->isAdmin() ? null : $this->name();
    }
}
