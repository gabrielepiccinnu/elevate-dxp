<?php

declare(strict_types=1);

namespace ElevateDxp\Export\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('export');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('base_path')->defaultValue('var/elevate-dxp/exports')
                    ->info('Allow-listed base directory (relative to project dir) for local targets.')->end()
                ->integerNode('chunk_size')->defaultValue(100)->min(1)->end()
                ->scalarNode('lock_dir')->defaultNull()
                    ->info('Directory for job lock files (default: system temp dir).')->end()
                ->arrayNode('jobs')
                    ->useAttributeAsKey('name')
                    ->validate()
                        ->ifTrue(static fn (array $jobs): bool => array_filter(array_keys($jobs), static fn ($k): bool => !preg_match('/^[A-Za-z0-9_-]+$/', (string) $k)) !== [])
                        ->thenInvalid('Export job names may only contain letters, digits, "_" and "-".')
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('description')->defaultNull()->end()
                            ->arrayNode('source')
                                ->isRequired()
                                ->children()
                                    ->enumNode('type')->values(['data_object', 'asset'])->isRequired()->end()
                                    ->scalarNode('class')->defaultNull()->info('Data object class name, e.g. Product.')->end()
                                    ->arrayNode('fields')->useAttributeAsKey('name')->scalarPrototype()->end()->end()
                                ->end()
                            ->end()
                            ->enumNode('format')->values(['csv', 'json', 'xml'])->isRequired()->end()
                            ->arrayNode('target')
                                ->isRequired()
                                ->children()
                                    ->enumNode('type')->values(['local', 'asset', 'http'])->isRequired()
                                        ->info('local | asset | http')->end()
                                    ->scalarNode('path')->isRequired()->info('Filename/asset path, or the URL for the http target.')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
