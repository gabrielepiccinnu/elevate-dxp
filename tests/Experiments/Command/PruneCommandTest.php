<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Experiments\Command;

use Doctrine\DBAL\Connection;
use ElevateDxp\Experiments\Command\PruneCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneCommandTest extends TestCase
{
    public function testCutoffs(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $c = PruneCommand::cutoffs($now, PruneCommand::DEFAULT_EVENTS_DAYS, PruneCommand::DEFAULT_PROFILES_DAYS);
        self::assertSame('2025-09-06 12:00:00', $c['events']);
        self::assertSame('2025-09-06 12:00:00', $c['profiles']);

        $c = PruneCommand::cutoffs($now, 30, 90);
        self::assertSame('2026-09-06 12:00:00', $c['events']);
        self::assertSame('2026-07-08 12:00:00', $c['profiles']);
        self::assertSame('2026-09-06 12:00:00', $c['recentSince'], 'the more recent cut-off defines a "recent" event');
        self::assertSame('2026-09-06 12:00:00', PruneCommand::cutoffs($now, 90, 30)['recentSince']);
    }

    public function testPruneDeletesInChunksAndRemovesOrphanAssignments(): void
    {
        $statements = [];
        $eventResults = [2, 2, 1];
        $idChunks = [['v1', 'v2'], ['v3']];
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$statements, &$eventResults): int {
            $statements[] = [$sql, $params];
            if (str_starts_with($sql, 'DELETE FROM edxp_event')) {
                return (int) array_shift($eventResults);
            }

            return str_starts_with($sql, 'DELETE FROM edxp_visitor_profile') ? \count($params) - 1 : 1;
        });
        $db->method('fetchFirstColumn')->willReturnCallback(static function () use (&$idChunks): array {
            return array_shift($idChunks) ?? [];
        });

        $c = ['events' => '2025-01-01 00:00:00', 'profiles' => '2024-01-01 00:00:00', 'recentSince' => '2025-01-01 00:00:00'];
        $result = (new PruneCommand($db))->prune($c, 2);

        self::assertSame(['events' => 5, 'profiles' => 3, 'assignments' => 2], $result);
        self::assertSame(['DELETE FROM edxp_event WHERE created_at < ? ORDER BY id LIMIT 2', ['2025-01-01 00:00:00']], $statements[0]);
        self::assertCount(3 + 2 * 2, $statements, '3 event chunks, then assignments + profiles per visitor chunk');

        [$assignSql, $assignParams] = $statements[3];
        self::assertSame(PruneCommand::assignmentDeleteSql('?,?'), $assignSql);
        self::assertStringContainsString('NOT EXISTS', $assignSql);
        self::assertSame(['v1', 'v2', '2025-01-01 00:00:00'], $assignParams);
        self::assertSame(['DELETE FROM edxp_visitor_profile WHERE visitor_id IN (?,?) AND last_seen < ?', ['v1', 'v2', '2024-01-01 00:00:00']], $statements[4]);
        self::assertSame(['v3', '2024-01-01 00:00:00'], $statements[6][1]);
    }

    public function testPruneIsIdempotentWhenNothingMatches(): void
    {
        $statements = 0;
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function () use (&$statements): int {
            ++$statements;

            return 0;
        });
        $db->method('fetchFirstColumn')->willReturn([]);
        $c = PruneCommand::cutoffs(new \DateTimeImmutable(), 10, 10);
        self::assertSame(['events' => 0, 'profiles' => 0, 'assignments' => 0], (new PruneCommand($db))->prune($c));
        self::assertSame(1, $statements);
    }

    public function testCommandDryRunAndValidation(): void
    {
        $writes = 0;
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturnOnConsecutiveCalls(10, 4, 3);
        $db->method('executeStatement')->willReturnCallback(static function () use (&$writes): int {
            ++$writes;

            return 0;
        });
        $db->method('fetchFirstColumn')->willReturn([]);
        $tester = new CommandTester(new PruneCommand($db));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('would delete 10 event(s)', $tester->getDisplay());
        self::assertSame(0, $writes);

        self::assertSame(Command::INVALID, $tester->execute(['--events-days' => '0']));
        self::assertSame(Command::INVALID, $tester->execute(['--profiles-days' => 'x']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--events-days' => '30', '--profiles-days' => '60']));
        self::assertSame(1, $writes);
    }
}
