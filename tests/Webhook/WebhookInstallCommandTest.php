<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Installer\Installer;
use ElevateDxp\Webhook\Command\WebhookInstallCommand;
use ElevateDxp\Webhook\Installer\WebhookInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WebhookInstallCommandTest extends TestCase
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

        $tester = new CommandTester(new WebhookInstallCommand(new WebhookInstaller(), $db));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertCount(2, $statements);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `edxp_webhook_delivery`', $statements[0][0]);
        self::assertStringContainsString('users_permission_definitions', $statements[1][0]);
        self::assertSame([WebhookInstaller::PERMISSION, Installer::PERMISSION_CATEGORY], $statements[1][1]);
    }

    public function testReportsDatabaseFailure(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willThrowException(new \RuntimeException('db down'));

        $tester = new CommandTester(new WebhookInstallCommand(new WebhookInstaller(), $db));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('db down', $tester->getDisplay());
    }
}
