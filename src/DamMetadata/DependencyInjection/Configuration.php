<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('dam_metadata');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->integerNode('batch_size')->defaultValue(200)->min(1)->info('Assets loaded per batch during bulk apply.')->end()
                ->arrayNode('schemas')
                    ->useAttributeAsKey('name')->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->defaultValue('')->end()
                            ->scalarNode('path_prefix')->defaultValue('/')->info('Apply to assets whose fullPath starts here.')->end()
                            ->arrayNode('asset_types')->scalarPrototype()->end()->defaultValue([])->info('Empty = any type (image, document, video, …).')->end()
                            ->arrayNode('fields')
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                                        ->enumNode('type')->values(['input', 'textarea', 'select', 'checkbox', 'number', 'date'])->defaultValue('input')->end()
                                        ->scalarNode('label')->defaultValue('')->end()
                                        ->arrayNode('options')->scalarPrototype()->end()->defaultValue([])->info('For select.')->end()
                                        ->scalarNode('default')->defaultNull()->info('Initial value used by "apply to folder" without a field.')->end()
                                        ->scalarNode('description')->defaultValue('')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
