<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class SsoModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_sso.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_sso.groups_claim', $config['groups_claim']);
        $container->setParameter('elevate_dxp_sso.identifier_claim', $config['identifier_claim']);
        $container->setParameter('elevate_dxp_sso.jit_provisioning', $config['jit_provisioning']);
        $container->setParameter('elevate_dxp_sso.providers', $config['providers']);
        $container->setParameter('elevate_dxp_sso.role_mapping', self::mergeRoleMappings($config['providers']));

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('sso.yaml');
    }

    /**
     * Merges all providers' role maps into one (deny-by-default lookup table). When two providers
     * map the same group, the later provider wins.
     *
     * @param array<string,array{role_mapping?:array<string,string>}> $providers
     *
     * @return array<string,string>
     */
    public static function mergeRoleMappings(array $providers): array
    {
        $roleMapping = [];
        foreach ($providers as $provider) {
            foreach ($provider['role_mapping'] ?? [] as $group => $role) {
                $roleMapping[(string) $group] = (string) $role;
            }
        }

        return $roleMapping;
    }

    public function key(): string
    {
        return 'sso';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
