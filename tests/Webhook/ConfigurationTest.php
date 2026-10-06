<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook;

use ElevateDxp\Webhook\DependencyInjection\Configuration;
use ElevateDxp\Webhook\Installer\WebhookInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public function testDefaults(): void
    {
        $c = $this->process([]);
        self::assertTrue($c['enabled']);
        self::assertFalse($c['sink_enabled']);
        self::assertSame('', $c['sink_secret']);
        self::assertSame(5, $c['timeout']);
        self::assertSame([], $c['subscriptions']);
    }

    public function testSubscription(): void
    {
        $c = $this->process(['subscriptions' => ['erp' => ['url' => 'https://erp/hook', 'secret' => 's', 'events' => ['object.update', 'document.add']]]]);
        self::assertEquals(['active' => true, 'url' => 'https://erp/hook', 'secret' => 's', 'events' => ['object.update', 'document.add']], $c['subscriptions']['erp']);
    }

    public function testUnknownEventIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['subscriptions' => ['erp' => ['url' => 'https://erp/hook', 'events' => ['object.explode']]]]);
    }

    public function testUrlIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['subscriptions' => ['erp' => ['events' => ['object.update']]]]);
    }

    public function testInstallerSchemaAndPermission(): void
    {
        $installer = new WebhookInstaller();
        $ddl = $installer->getSchema();
        self::assertCount(1, $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `edxp_webhook_delivery`', $ddl[0]);
        self::assertStringContainsString('utf8mb4', $ddl[0]);
        self::assertStringContainsString('`status`', $ddl[0]);
        self::assertSame('elevate_dxp_webhook', WebhookInstaller::PERMISSION);
    }
}
