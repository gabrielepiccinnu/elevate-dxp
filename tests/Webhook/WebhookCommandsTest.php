<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook;

use Doctrine\DBAL\Connection;
use ElevateDxp\Tests\Webhook\Webhook\Fakes;
use ElevateDxp\Tests\Webhook\Webhook\RecordingMessageBus;
use ElevateDxp\Webhook\Command\WebhookPruneCommand;
use ElevateDxp\Webhook\Command\WebhookTestCommand;
use ElevateDxp\Webhook\Repository\WebhookDeliveryRepository;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class WebhookCommandsTest extends TestCase
{
    private RecordingMessageBus $bus;

    private function testCommand(?\Throwable $busFailure = null, bool $handledInline = false): CommandTester
    {
        $this->bus = Fakes::bus($busFailure, $handledInline);
        $registry = new SubscriptionRegistry([
            'erp' => ['url' => 'http://erp', 'events' => ['object.update']],
            'off' => ['active' => false, 'url' => 'http://off', 'events' => ['object.update']],
        ]);

        return new CommandTester(new WebhookTestCommand(new WebhookDispatcher($registry, $this->bus), Fakes::sender([])));
    }

    public function testAsyncRefusesInactiveAndUnknownSubscriptions(): void
    {
        $tester = $this->testCommand();
        self::assertSame(Command::INVALID, $tester->execute(['subscription' => 'off', '--async' => true]));
        self::assertStringContainsString('inactive', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['subscription' => 'nope', '--async' => true]));
        self::assertCount(0, $this->bus->messages);
    }

    public function testAsyncReportsActualOutcome(): void
    {
        $tester = $this->testCommand();
        self::assertSame(Command::SUCCESS, $tester->execute(['subscription' => 'erp', '--async' => true]));
        self::assertStringContainsString('queued', $tester->getDisplay());

        $tester = $this->testCommand(null, true);
        self::assertSame(Command::SUCCESS, $tester->execute(['subscription' => 'erp', '--async' => true]));
        self::assertStringContainsString('delivered', $tester->getDisplay());

        $tester = $this->testCommand(new HandlerFailedException(new Envelope(new \stdClass()), [new \RuntimeException('HTTP 503')]));
        self::assertSame(Command::FAILURE, $tester->execute(['subscription' => 'erp', '--async' => true]));
        self::assertStringContainsString('HTTP 503', $tester->getDisplay());
    }

    public function testPruneDeletesInChunksUntilDone(): void
    {
        $calls = [];
        $results = [1000, 1000, 7];
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$calls, &$results): int {
            $calls[] = [$sql, $params];

            return (int) array_shift($results);
        });
        $deleted = (new WebhookDeliveryRepository($db))->prune(new \DateTimeImmutable('2026-01-31 12:00:00'), 1000);
        self::assertSame(2007, $deleted);
        self::assertCount(3, $calls);
        self::assertSame('DELETE FROM edxp_webhook_delivery WHERE created_at < ? ORDER BY id LIMIT 1000', $calls[0][0]);
        self::assertSame(['2026-01-31 12:00:00'], $calls[0][1]);
    }

    public function testPruneCommand(): void
    {
        $sql = [];
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function (string $s, array $params = []) use (&$sql): int {
            $sql[] = $s;

            return 0;
        });
        $db->method('fetchOne')->willReturn(12);
        $tester = new CommandTester(new WebhookPruneCommand(new WebhookDeliveryRepository($db)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('12 delivery row(s)', $tester->getDisplay());
        self::assertSame([], $sql, 'dry run deletes nothing');

        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '7']));
        self::assertCount(1, $sql);
        self::assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
        self::assertSame(Command::INVALID, $tester->execute(['--days' => 'abc']));
    }

    public function testPruneCutoff(): void
    {
        $now = new \DateTimeImmutable('2026-03-31 10:00:00');
        self::assertSame('2026-03-01 10:00:00', WebhookPruneCommand::cutoff($now, WebhookPruneCommand::DEFAULT_DAYS)->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-24 10:00:00', WebhookPruneCommand::cutoff($now, 7)->format('Y-m-d H:i:s'));
    }
}
