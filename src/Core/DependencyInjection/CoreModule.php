<?php

declare(strict_types=1);

namespace ElevateDxp\Core\DependencyInjection;

use ElevateDxp\Core\Admin\AdminResourceInterface;
use ElevateDxp\Core\Installer\ModuleInstaller;
use ElevateDxp\Core\Messenger\AsyncMessageInterface;
use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CoreModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $container->setParameter('env(ELEVATE_DXP_MESSENGER_DSN)', 'sync://');
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'transports' => ['elevate_dxp' => '%env(ELEVATE_DXP_MESSENGER_DSN)%'],
                'routing' => [AsyncMessageInterface::class => 'elevate_dxp'],
            ],
        ]);
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_core.audit_enabled', $config['audit']['enabled']);
        $container->setParameter('elevate_dxp_core.default_policy', $config['security']['default_policy']);
        $container->setParameter('elevate_dxp_core.allowed_actions', $config['security']['allowed_actions']);

        // Every admin resource of any module is collected by the shared ResourceController.
        $container->registerForAutoconfiguration(AdminResourceInterface::class)
            ->addTag(AdminResourceInterface::TAG);
        $container->registerForAutoconfiguration(ModuleInstaller::class)
            ->addTag(ModuleInstaller::TAG);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('core.yaml');
    }

    public function key(): string
    {
        return 'core';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
