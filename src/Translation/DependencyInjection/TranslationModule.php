<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class TranslationModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_translation.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_translation.provider', $config['provider']);
        $container->setParameter('elevate_dxp_translation.languages', array_values($config['languages']));
        $container->setParameter('elevate_dxp_translation.libretranslate', $config['libretranslate']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('translation.yaml');
    }

    public function key(): string
    {
        return 'translation';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
