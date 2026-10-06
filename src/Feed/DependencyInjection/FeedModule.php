<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class FeedModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_feed.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_feed.chunk_size', $config['chunk_size']);
        $container->setParameter('elevate_dxp_feed.feeds', $config['feeds']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('feed.yaml');
    }

    public function key(): string
    {
        return 'feed';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
