<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Login;

use ElevateDxp\Sso\Identity\IdentityDescriptor;
use ElevateDxp\Sso\Identity\IdentityMapperInterface;
use ElevateDxp\Sso\Provisioning\OpenDxpUserProvisioner;
use ElevateDxp\Sso\Provisioning\RolePlan;
use ElevateDxp\Sso\Provisioning\UserDirectory;
use OpenDxp\Model\User;

/**
 * The single entry point an OIDC authenticator calls after it has VALIDATED the ID token
 * (signature, issuer, audience, expiry, nonce): claims in, OpenDXP user out — or a denial.
 *
 * Decision order (deny-by-default):
 *  1. SSO disabled                         → deny
 *  2. no identifier or no mapped role      → deny
 *  3. mapped roles grant nothing (none of them exists in OpenDXP and no admin alias) → deny
 *  4. user missing and JIT disabled        → deny
 *  5. existing user deactivated by an admin→ deny (SSO never re-activates a disabled account)
 *  6. otherwise upsert user + sync roles   → allow
 */
class SsoLoginService
{
    public function __construct(
        private readonly IdentityMapperInterface $mapper,
        private readonly OpenDxpUserProvisioner $provisioner,
        private readonly UserDirectory $directory,
        private readonly bool $enabled = false,
        private readonly bool $jitProvisioning = false,
    ) {
    }

    /** @param array<string,mixed> $claims */
    public function decide(array $claims): SsoDecision
    {
        $identity = $this->mapper->mapClaims($claims);
        if (!$this->enabled) {
            return SsoDecision::deny($identity, 'SSO is disabled (elevate_dxp.sso.enabled: false).');
        }

        return $this->decideFor($identity, $this->directory->findUser($identity->identifier));
    }

    /** Pure decision logic, also used by the admin "simulate mapping" action. */
    public function decideFor(IdentityDescriptor $identity, ?User $existing, ?bool $jit = null): SsoDecision
    {
        $jit ??= $this->jitProvisioning;
        if ($identity->identifier === '') {
            return SsoDecision::deny($identity, 'No identifier claim present.');
        }
        if (!$identity->isAuthorized()) {
            return SsoDecision::deny($identity, 'No IdP group is mapped to a role (deny-by-default).');
        }
        if (!$this->grantsAnything($identity)) {
            return SsoDecision::deny($identity, 'None of the mapped roles exists in OpenDXP (deny-by-default).');
        }
        if ($existing === null && !$jit) {
            return SsoDecision::deny($identity, 'User does not exist and JIT provisioning is disabled.');
        }
        if ($existing !== null && !$existing->isActive()) {
            return SsoDecision::deny($identity, 'User exists but is deactivated.');
        }

        return SsoDecision::allow($identity, $existing === null ? 'User will be created (JIT) and roles synced.' : 'Existing user; roles will be synced.');
    }

    /** True when the identity sets the admin flag or maps to at least one existing OpenDXP role. */
    private function grantsAnything(IdentityDescriptor $identity): bool
    {
        $plan = RolePlan::fromIdentity($identity);
        if ($plan->admin) {
            return true;
        }
        foreach ($plan->roleNames as $name) {
            if ($this->directory->roleId($name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $claims
     *
     * @throws SsoDeniedException
     */
    public function login(array $claims): User
    {
        $decision = $this->decide($claims);
        if (!$decision->allowed) {
            throw new SsoDeniedException($decision->reason);
        }

        return $this->provisioner->provision($decision->identity);
    }
}
