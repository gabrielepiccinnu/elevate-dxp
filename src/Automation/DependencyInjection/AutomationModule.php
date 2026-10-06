<?php

declare(strict_types=1);

namespace ElevateDxp\Automation\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class AutomationModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_automation.enabled', $config['enabled']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('automation.yaml');
    }

    public function key(): string
    {
        return 'automation';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
