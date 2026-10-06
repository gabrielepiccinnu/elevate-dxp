<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Sso;

use ElevateDxp\Sso\Admin\SsoMappingResource;
use ElevateDxp\Sso\DependencyInjection\SsoModule;
use ElevateDxp\Sso\Identity\IdentityDescriptor;
use ElevateDxp\Sso\Identity\RoleMappingIdentityMapper;
use ElevateDxp\Sso\Login\SsoDeniedException;
use ElevateDxp\Sso\Login\SsoLoginService;
use ElevateDxp\Sso\Provisioning\OpenDxpUserProvisioner;
use ElevateDxp\Sso\Provisioning\RolePlan;
use ElevateDxp\Sso\Provisioning\UserDirectory;
use OpenDxp\Model\User;
use PHPUnit\Framework\TestCase;

final class SsoDecisionTest extends TestCase
{
    private const PROVIDERS = [
        'keycloak' => ['issuer' => 'https://idp.example/realms/x', 'client_id' => 'dxp', 'client_secret' => 's3cr3t', 'scopes' => ['openid'], 'role_mapping' => ['admins' => 'admin', 'editors' => 'Editor']],
        'azure' => ['issuer' => 'https://login.example', 'client_id' => 'a', 'client_secret' => '', 'scopes' => ['openid'], 'role_mapping' => ['editors' => 'Author', 'ghosts' => 'NoSuchRole']],
    ];

    private function directory(?User $user = null): UserDirectory
    {
        return new class($user) extends UserDirectory {
            public function __construct(private readonly ?User $user)
            {
            }

            public function roleId(string $name): ?int
            {
                return ['Editor' => 3, 'Author' => 4][$name] ?? null;
            }

            public function findUser(string $name): ?User
            {
                return $this->user;
            }
        };
    }

    private function service(bool $enabled = true, bool $jit = false, ?User $user = null): SsoLoginService
    {
        $dir = $this->directory($user);

        return new SsoLoginService(
            new RoleMappingIdentityMapper('groups', SsoModule::mergeRoleMappings(self::PROVIDERS)),
            new OpenDxpUserProvisioner($dir),
            $dir,
            $enabled,
            $jit,
        );
    }

    public function testRolePlanSeparatesAdminFlag(): void
    {
        $plan = RolePlan::fromIdentity(new IdentityDescriptor('u', null, ['Editor', 'admin', 'ROLE_OPENDXP_ADMIN', 'Editor']));
        self::assertTrue($plan->admin);
        self::assertSame(['Editor'], $plan->roleNames);
        self::assertFalse(RolePlan::fromIdentity(new IdentityDescriptor('u', null, ['Editor']))->admin);
        self::assertTrue(RolePlan::isAdminAlias('role_opendxp_admin'), 'aliases are case-insensitive');
        self::assertFalse(RolePlan::isAdminAlias('ROLE_ADMIN'));
    }

    public function testMergedMappingLaterProviderWins(): void
    {
        self::assertSame(['admins' => 'admin', 'editors' => 'Author', 'ghosts' => 'NoSuchRole'], SsoModule::mergeRoleMappings(self::PROVIDERS));
    }

    public function testDisabledSsoDeniesEverything(): void
    {
        $d = $this->service(false)->decide(['sub' => 'jane', 'groups' => ['admins']]);
        self::assertFalse($d->allowed);
        self::assertStringContainsString('disabled', $d->reason);
    }

    public function testUnmappedGroupsAreDenied(): void
    {
        $d = $this->service()->decide(['sub' => 'jane', 'groups' => ['random']]);
        self::assertFalse($d->allowed);
        self::assertStringContainsString('deny-by-default', $d->reason);
    }

    public function testMissingUserWithoutJitIsDeniedAndWithJitAllowed(): void
    {
        self::assertFalse($this->service(true, false)->decide(['sub' => 'jane', 'groups' => ['editors']])->allowed);
        $d = $this->service(true, true)->decide(['sub' => 'jane', 'groups' => ['editors']]);
        self::assertTrue($d->allowed);
        self::assertSame(['Author'], $d->identity->roles);
    }

    public function testDeactivatedUserIsNeverReactivated(): void
    {
        $user = new User();
        $user->setActive(false);
        $d = $this->service(true, true, $user)->decide(['sub' => 'jane', 'groups' => ['editors']]);
        self::assertFalse($d->allowed);
        self::assertStringContainsString('deactivated', $d->reason);
    }

    public function testLoginThrowsOnDenial(): void
    {
        $this->expectException(SsoDeniedException::class);
        $this->service(true, true)->login(['sub' => '', 'groups' => ['editors']]);
    }

    public function testEmptyIdentifierIsNotAuthorized(): void
    {
        self::assertFalse((new IdentityDescriptor('', null, ['Editor']))->isAuthorized());
    }

    public function testIdentifierClaimIsConfigurable(): void
    {
        $id = (new RoleMappingIdentityMapper('groups', ['g' => 'R'], 'preferred_username'))->mapClaims(['sub' => 'x1', 'preferred_username' => 'jane', 'groups' => ['g', ['nested']]]);
        self::assertSame('jane', $id->identifier);
        self::assertSame(['R'], $id->roles);
    }

    private function resource(bool $jit = false): SsoMappingResource
    {
        $dir = $this->directory();

        return new SsoMappingResource(self::PROVIDERS, $this->service(true, $jit), $dir, 'groups', 'sub', true, $jit);
    }

    public function testMappingListShowsEffects(): void
    {
        $rows = $this->resource()->list([])['data'];
        self::assertCount(4, $rows);
        $byId = array_column($rows, 'target', 'id');
        self::assertStringContainsString('admin flag', $byId['keycloak:admins']);
        self::assertSame('Assigns role #3', $byId['keycloak:editors']);
        self::assertStringContainsString('does not exist', $byId['azure:ghosts']);
        self::assertFalse($this->resource()->getSchema()['canEdit']);
    }

    public function testSimulateUsesSelectedProviderAndChangesNothing(): void
    {
        $result = $this->resource(true)->runAction('simulate', null, ['claims' => ['sub' => 'jane', 'email' => 'j@x', 'groups' => ['editors', 'random']], 'provider' => 'keycloak']);
        $rows = array_column($result['rows'], 'result', 'check');
        self::assertSame('jane', $rows['Identifier (sub)']);
        self::assertSame('Editor', $rows['Roles']);
        self::assertSame('random', $rows['Unmapped groups (grant nothing)']);
        self::assertStringStartsWith('ALLOW', $rows['Decision']);

        $denied = array_column($this->resource(false)->simulate('{"sub":"jane","groups":["ghosts"]}'), 'result', 'check');
        self::assertStringContainsString('missing in OpenDXP', $denied['Roles']);
        self::assertStringStartsWith('DENY', $denied['Decision'], 'JIT disabled and user missing');
    }

    public function testSimulateRejectsBadInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resource()->simulate('["not","an","object"]');
    }

    public function testSettingsNeverRevealSecrets(): void
    {
        $rows = $this->resource()->runAction('settings', null, [])['rows'];
        self::assertStringNotContainsString('s3cr3t', json_encode($rows));
    }
}
