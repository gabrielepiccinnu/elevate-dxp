<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use ElevateDxp\Portal\Search\SearchBackendInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class PortalModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_portal.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_portal.products_class', $config['products_class']);
        $container->setParameter('elevate_dxp_portal.object_image_field', $config['object_image_field']);
        $container->setParameter('elevate_dxp_portal.allowed_asset_paths', $config['allowed_asset_paths']);
        $container->setParameter('elevate_dxp_portal.share', $config['share']);
        $container->setParameter('elevate_dxp_portal.search', $config['search']);
        $container->setParameter('elevate_dxp_portal.search.backend', $config['search']['backend']);
        $container->setParameter('elevate_dxp_portal.search.page_size', $config['search']['page_size']);
        $container->setParameter('elevate_dxp_portal.search.max_page_size', $config['search']['max_page_size']);
        $container->setParameter('elevate_dxp_portal.branding', $config['branding']);

        // Any service implementing the interface becomes a selectable search backend.
        $container->registerForAutoconfiguration(SearchBackendInterface::class)
            ->addTag(SearchBackendInterface::TAG);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('portal.yaml');
    }

    public function key(): string
    {
        return 'portal';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
