<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Statistics;

use ElevateDxp\Statistics\Report\ReadOnlySqlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadOnlySqlGuardTest extends TestCase
{
    public function testNormalizesTrailingSemicolon(): void
    {
        self::assertSame('SELECT 1', ReadOnlySqlGuard::assertReadOnly("  SELECT 1;\n"));
        self::assertTrue(ReadOnlySqlGuard::isReadOnly('WITH x AS (SELECT 1 AS n) SELECT n FROM x'));
        self::assertTrue(ReadOnlySqlGuard::isReadOnly('SELECT charset, offset FROM t'));
    }

    #[DataProvider('unsafe')]
    public function testRejects(string $sql): void
    {
        self::assertFalse(ReadOnlySqlGuard::isReadOnly($sql));
    }

    /** @return iterable<array{string}> */
    public static function unsafe(): iterable
    {
        yield 'empty' => [''];
        yield 'into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/x'"];
        yield 'dumpfile' => ["SELECT 1 INTO DUMPFILE '/tmp/x'"];
        yield 'for update' => ['SELECT * FROM t FOR UPDATE'];
        yield 'lock in share mode' => ['SELECT * FROM t LOCK IN SHARE MODE'];
        yield 'show' => ['SHOW TABLES'];
        yield 'set' => ['SET @a = 1'];
        yield 'stacked' => ['SELECT 1;SELECT 2'];
        yield 'with delete' => ['WITH x AS (SELECT 1) DELETE FROM t'];
        yield 'call' => ['CALL proc()'];
    }
}
