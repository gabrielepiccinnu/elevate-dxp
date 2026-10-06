<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ExperimentsModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        foreach ([
            'enabled' => $config['enabled'],
            'cookie_name' => $config['visitor']['cookie_name'],
            'cookie_ttl_days' => $config['visitor']['cookie_ttl_days'],
            'secret' => $config['visitor']['secret'],
            'consent_cookie' => $config['visitor']['consent_cookie'],
            'excluded_paths' => $config['visitor']['excluded_paths'],
            'profile_enabled' => $config['profile']['enabled'],
            'runtime_inject' => $config['runtime']['inject'],
            'tracking' => $config['tracking'],
            'analytics' => $config['analytics'],
        ] as $key => $value) {
            $container->setParameter('elevate_dxp_experiments.'.$key, $value);
        }

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('experiments.yaml');
    }

    public function key(): string
    {
        return 'experiments';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
