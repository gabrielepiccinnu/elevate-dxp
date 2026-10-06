<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Core\Audit;

use ElevateDxp\Core\Audit\SecretMasker;
use ElevateDxp\Core\Security\DenyByDefaultAccessPolicy;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase
{
    public function testMasksNestedSecrets(): void
    {
        $out = SecretMasker::mask(['id' => 5, 'Token' => 'abc', 'nested' => ['password' => 'x', 'ok' => 'y']]);
        self::assertSame(5, $out['id']);
        self::assertSame('***', $out['Token']);
        self::assertSame('***', $out['nested']['password']);
        self::assertSame('y', $out['nested']['ok']);
    }

    public function testDenyByDefault(): void
    {
        $policy = new DenyByDefaultAccessPolicy('deny', ['export.run']);
        self::assertTrue($policy->isAllowed('export.run'));
        self::assertFalse($policy->isAllowed('export.delete'));
        self::assertTrue((new DenyByDefaultAccessPolicy('allow'))->isAllowed('anything'));
    }
}
