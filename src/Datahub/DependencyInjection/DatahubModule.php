<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class DatahubModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_datahub.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_datahub.api_key_header', $config['api_key_header']);
        $container->setParameter('elevate_dxp_datahub.api_key', $config['api_key']);
        $container->setParameter('elevate_dxp_datahub.max_limit', $config['max_limit']);
        $container->setParameter('elevate_dxp_datahub.endpoints', $config['endpoints']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('datahub.yaml');
    }

    public function key(): string
    {
        return 'datahub';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
