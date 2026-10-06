<?php

declare(strict_types=1);

namespace ElevateDxp\Export\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ExportModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_export.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_export.base_path', $config['base_path']);
        $container->setParameter('elevate_dxp_export.chunk_size', $config['chunk_size']);
        $container->setParameter('elevate_dxp_export.lock_dir', $config['lock_dir']);
        $container->setParameter('elevate_dxp_export.jobs', $config['jobs']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('export.yaml');
    }

    public function key(): string
    {
        return 'export';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
