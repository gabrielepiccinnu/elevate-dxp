<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class WebhookModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_webhook.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_webhook.sink_enabled', $config['sink_enabled']);
        $container->setParameter('elevate_dxp_webhook.sink_secret', $config['sink_secret']);
        $container->setParameter('elevate_dxp_webhook.timeout', $config['timeout']);
        $container->setParameter('elevate_dxp_webhook.subscriptions', $config['subscriptions']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('webhook.yaml');
    }

    public function key(): string
    {
        return 'webhook';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
