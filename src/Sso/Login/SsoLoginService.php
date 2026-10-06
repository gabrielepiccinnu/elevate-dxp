<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Login;

use ElevateDxp\Sso\Identity\IdentityDescriptor;
use ElevateDxp\Sso\Identity\IdentityMapperInterface;
use ElevateDxp\Sso\Provisioning\OpenDxpUserProvisioner;
use ElevateDxp\Sso\Provisioning\UserDirectory;
use OpenDxp\Model\User;

/**
 * The single entry point an OIDC authenticator calls after it has VALIDATED the ID token
 * (signature, issuer, audience, expiry, nonce): claims in, OpenDXP user out — or a denial.
 *
 * Decision order (deny-by-default):
 *  1. SSO disabled                         → deny
 *  2. no identifier or no mapped role      → deny
 *  3. user missing and JIT disabled        → deny
 *  4. existing user deactivated by an admin→ deny (SSO never re-activates a disabled account)
 *  5. otherwise upsert user + sync roles   → allow
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
            return SsoDecision::deny($identity, 'SSO is disabled (elevate_dxp_sso.enabled: false).');
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
        if ($existing === null && !$jit) {
            return SsoDecision::deny($identity, 'User does not exist and JIT provisioning is disabled.');
        }
        if ($existing !== null && !$existing->isActive()) {
            return SsoDecision::deny($identity, 'User exists but is deactivated.');
        }

        return SsoDecision::allow($identity, $existing === null ? 'User will be created (JIT) and roles synced.' : 'Existing user; roles will be synced.');
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
