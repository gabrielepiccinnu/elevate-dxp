<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Command;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Installer\Installer;
use ElevateDxp\Portal\Command\PortalInstallCommand;
use ElevateDxp\Portal\Installer\PortalInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PortalInstallCommandTest extends TestCase
{
    public function testAppliesSchemaAndRegistersPermission(): void
    {
        /** @var list<array{string, list<mixed>}> $statements */
        $statements = [];
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
            $statements[] = [$sql, $params];

            return 0;
        });

        $tester = new CommandTester(new PortalInstallCommand(new PortalInstaller(), $db));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertCount(4, $statements);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS edxp_portal_collection ', $statements[0][0]);
        self::assertStringContainsString('users_permission_definitions', $statements[3][0]);
        self::assertSame([PortalInstaller::PERMISSION, Installer::PERMISSION_CATEGORY], $statements[3][1]);
    }

    public function testReportsDatabaseFailure(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willThrowException(new \RuntimeException('db down'));

        $tester = new CommandTester(new PortalInstallCommand(new PortalInstaller(), $db));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('db down', $tester->getDisplay());
    }
}
