<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class DamMetadataModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_dam_metadata.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_dam_metadata.schemas', $config['schemas']);
        $container->setParameter('elevate_dxp_dam_metadata.batch_size', $config['batch_size']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('dam_metadata.yaml');
    }

    public function key(): string
    {
        return 'dam_metadata';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
