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
        $enabled = [];
        foreach (Modules::all() as $module) {
            $module->load($config[$module->key()], $container);
            // ElevateDxp\<Segment>\DependencyInjection\XModule => <Segment>
            $segment = explode('\\', $module::class)[1];
            $enabled[$segment] = (bool) ($config[$module->key()]['enabled'] ?? true);
        }
        // Admin resources of disabled modules are hidden from the menu and refused by the API.
        $container->setParameter('elevate_dxp.enabled_modules', $enabled);
    }

    public function getAlias(): string
    {
        return 'elevate_dxp';
    }
}
