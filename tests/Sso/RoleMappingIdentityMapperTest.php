<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Sso;

use ElevateDxp\Sso\Identity\RoleMappingIdentityMapper;
use PHPUnit\Framework\TestCase;

final class RoleMappingIdentityMapperTest extends TestCase
{
    private function mapper(): RoleMappingIdentityMapper
    {
        return new RoleMappingIdentityMapper('groups', [
            'opendxp-admins' => 'admin',
            'editors' => 'Editor',
        ]);
    }

    public function testMapsKnownGroupsToRoles(): void
    {
        $id = $this->mapper()->mapClaims([
            'sub' => 'u1', 'email' => 'a@b.c', 'groups' => ['editors'],
        ]);
        self::assertSame('u1', $id->identifier);
        self::assertSame('a@b.c', $id->email);
        self::assertSame(['Editor'], $id->roles);
        self::assertTrue($id->isAuthorized());
    }

    public function testDenyByDefaultForUnmappedGroups(): void
    {
        $id = $this->mapper()->mapClaims(['sub' => 'u2', 'groups' => ['random-idp-group', 'another']]);
        self::assertSame([], $id->roles);
        self::assertFalse($id->isAuthorized(), 'unmapped groups must grant no roles');
    }

    public function testStringGroupClaimIsAccepted(): void
    {
        $id = $this->mapper()->mapClaims(['sub' => 'u3', 'groups' => 'opendxp-admins']);
        self::assertSame(['admin'], $id->roles);
    }

    public function testNoGroupsClaimMeansNoRoles(): void
    {
        $id = $this->mapper()->mapClaims(['sub' => 'u4', 'email' => 'x@y.z']);
        self::assertFalse($id->isAuthorized());
    }

    public function testDuplicateGroupsDoNotDuplicateRoles(): void
    {
        $id = $this->mapper()->mapClaims(['sub' => 'u5', 'groups' => ['editors', 'editors']]);
        self::assertSame(['Editor'], $id->roles);
    }
}
