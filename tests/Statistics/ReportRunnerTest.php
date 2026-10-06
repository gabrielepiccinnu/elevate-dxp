<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Statistics;

use Doctrine\DBAL\Connection;
use ElevateDxp\Statistics\Report\ReportRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Report execution and the read-only SQL guard. */
final class ReportRunnerTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function runner(string $sql, array $rows = [['a' => 1, 'b' => 2]], int $maxRows = 1000): ReportRunner
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);
        $conn->method('fetchAssociative')->willReturn($rows[0] ?? false);

        return new ReportRunner(['r' => ['sql' => $sql, 'chart' => 'bar', 'x' => 'a', 'y' => 'b']], $conn, $maxRows);
    }

    public function testValidSelectRuns(): void
    {
        $res = $this->runner('SELECT a, b FROM t')->run('r');
        self::assertSame(['a', 'b'], $res['columns']);
        self::assertCount(1, $res['rows']);
        self::assertFalse($res['truncated']);
    }

    public function testMeta(): void
    {
        $meta = $this->runner('SELECT 1')->meta('r');
        self::assertSame('bar', $meta['chart']);
        self::assertSame('a', $meta['x']);
        self::assertSame('r', $meta['label'], 'empty label falls back to the name');
        self::assertNull($this->runner('SELECT 1')->meta('nope'));
    }

    #[DataProvider('unsafeQueries')]
    public function testUnsafeQueriesRejected(string $sql): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner($sql)->run('r');
    }

    /** @return array<int,array<int,string>> */
    public static function unsafeQueries(): array
    {
        return [
            ['DELETE FROM t'],
            ['UPDATE t SET a=1'],
            ['DROP TABLE t'],
            ['SELECT 1; DROP TABLE t'],
            ['INSERT INTO t VALUES (1)'],
            ['TRUNCATE t'],
        ];
    }

    public function testUnknownReport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner('SELECT 1')->run('does_not_exist');
    }

    public function testRowsAreCappedAtMaxRows(): void
    {
        $rows = [['a' => 1], ['a' => 2], ['a' => 3]];
        $res = $this->runner('SELECT a FROM t', $rows, 2)->run('r');
        self::assertCount(2, $res['rows']);
        self::assertTrue($res['truncated']);
    }

    public function testSelectIsWrappedWithLimitSoTheDatabaseNeverReturnsMoreThanMaxRows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())->method('fetchAllAssociative')
            ->with('SELECT * FROM (SELECT a FROM t) AS edxp_q LIMIT 3')
            ->willReturn([['a' => 1], ['a' => 2], ['a' => 3]]);
        $res = (new ReportRunner(['r' => ['sql' => 'SELECT a FROM t;']], $conn, 2))->run('r');
        self::assertCount(2, $res['rows']);
        self::assertTrue($res['truncated']);
    }

    public function testExplicitLimitIsCappedByMaxRows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())->method('fetchAllAssociative')
            ->with('SELECT * FROM (SELECT a FROM t) AS edxp_q LIMIT 6')
            ->willReturn([]);
        (new ReportRunner(['r' => ['sql' => 'SELECT a FROM t']], $conn, 10))->run('r', 5);
    }

    public function testWithQueryIsStreamedAndStoppedAfterMaxRows(): void
    {
        $fetched = 0;
        $gen = (static function () use (&$fetched): \Generator {
            for ($i = 1; $i <= 100; ++$i) {
                ++$fetched;
                yield ['n' => $i];
            }
        })();
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('fetchAllAssociative');
        $conn->expects(self::once())->method('iterateAssociative')
            ->with('WITH x AS (SELECT 1 AS n) SELECT n FROM x')
            ->willReturn($gen);
        $res = (new ReportRunner(['r' => ['sql' => 'WITH x AS (SELECT 1 AS n) SELECT n FROM x']], $conn, 2))->run('r');
        self::assertCount(2, $res['rows']);
        self::assertTrue($res['truncated']);
        self::assertSame(3, $fetched, 'iteration stops after max_rows + 1 rows');
    }

    public function testLimitSql(): void
    {
        self::assertSame('SELECT * FROM (SELECT 1) AS edxp_q LIMIT 1', ReportRunner::limitSql('SELECT 1', 0));
    }

    public function testColumnsDerivedFromFirstRowOrConfig(): void
    {
        self::assertSame(['a', 'b'], $this->runner('SELECT a, b FROM t')->columns('r'));

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('fetchAssociative');
        $runner = new ReportRunner(['r' => ['sql' => 'SELECT a FROM t', 'columns' => ['x', 'y']]], $conn);
        self::assertSame(['x', 'y'], $runner->columns('r'));
    }

    public function testQueryRunsInReadOnlyTransaction(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('isTransactionActive')->willReturn(false);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $statements = [];
        $conn->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 0;
        });
        (new ReportRunner(['r' => ['sql' => 'SELECT 1']], $conn))->run('r');
        self::assertSame(['START TRANSACTION READ ONLY', 'ROLLBACK'], $statements);
    }
}
