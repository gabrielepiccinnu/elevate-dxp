<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\DependencyInjection;

use ElevateDxp\Core\Module\ModuleInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class InsightsModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_insights.data_quality.profiles', $config['data_quality']['profiles']);
        $container->setParameter('elevate_dxp_insights.data_quality.sample_limit', $config['data_quality']['sample_limit']);
        $container->setParameter('elevate_dxp_insights.copilot.api_key', $config['copilot']['api_key']);
        $container->setParameter('elevate_dxp_insights.copilot.model', $config['copilot']['model']);
        $container->setParameter('elevate_dxp_insights.copilot.max_tokens', $config['copilot']['max_tokens']);
        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('insights.yaml');
    }

    public function key(): string
    {
        return 'insights';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
