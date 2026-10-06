<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('statistics');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->integerNode('max_rows')->defaultValue(1000)->min(1)->info('Hard cap on rows returned to the admin UI.')->end()
                ->booleanNode('report_panels')->defaultTrue()
                    ->info('Register one admin "report" panel per configured report (in addition to the catalogue).')->end()
                ->arrayNode('reports')
                    ->useAttributeAsKey('name')
                    ->validate()
                        ->ifTrue(static fn (array $r): bool => array_filter(array_keys($r), static fn ($k): bool => !preg_match('/^[A-Za-z0-9_-]+$/', (string) $k)) !== [])
                        ->thenInvalid('Report names may only contain letters, digits, "_" and "-".')
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->defaultValue('')->end()
                            ->scalarNode('sql')->isRequired()->cannotBeEmpty()->info('Read-only SELECT/WITH query.')->end()
                            ->enumNode('chart')->values(['bar', 'line', 'pie', 'none'])->defaultValue('bar')->end()
                            ->scalarNode('x')->defaultNull()->info('Column used as label/axis.')->end()
                            ->scalarNode('y')->defaultNull()->info('Column used as numeric value.')->end()
                            ->arrayNode('columns')->scalarPrototype()->end()->defaultValue([])
                                ->info('Optional column list for the admin panel; derived from the query (and cached) when empty.')->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
