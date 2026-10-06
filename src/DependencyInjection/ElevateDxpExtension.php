<?php

declare(strict_types=1);

namespace ElevateDxp\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

final class ElevateDxpExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        foreach (Modules::all() as $module) {
            $module->prepend($container);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach (Modules::all() as $module) {
            $module->load($config[$module->key()], $container);
        }
    }

    public function getAlias(): string
    {
        return 'elevate_dxp';
    }
}
