<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\DependencyInjection;

use ElevateDxp\Core\Admin\AdminResourceInterface;
use ElevateDxp\Core\Module\ModuleInterface;
use ElevateDxp\Statistics\Admin\StatisticsReportResource;
use ElevateDxp\Statistics\Report\ReportRunner;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class StatisticsModule implements ModuleInterface
{
    public function prepend(ContainerBuilder $container): void
    {
    }

    public function load(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('elevate_dxp_statistics.enabled', $config['enabled']);
        $container->setParameter('elevate_dxp_statistics.reports', $config['reports']);
        $container->setParameter('elevate_dxp_statistics.max_rows', $config['max_rows']);

        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('statistics.yaml');

        if (!$config['enabled'] || !$config['report_panels']) {
            return;
        }

        // One "report" admin panel per configured report: each report has its own columns and chart.
        foreach (array_keys($config['reports']) as $name) {
            $definition = (new Definition(StatisticsReportResource::class))
                ->setArguments([
                    '$name' => (string) $name,
                    '$runner' => new Reference(ReportRunner::class),
                    '$cache' => new Reference('cache.app', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
                ])
                ->addTag(AdminResourceInterface::TAG);
            $container->setDefinition('elevate_dxp_statistics.report_resource.'.$name, $definition);
        }
    }

    public function key(): string
    {
        return 'statistics';
    }

    public function configuration(): Configuration
    {
        return new Configuration();
    }
}
