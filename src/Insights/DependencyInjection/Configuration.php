<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('insights');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('data_quality')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('sample_limit')->defaultValue(500)->min(1)->end()
                        ->arrayNode('profiles')
                            ->info('DataObject class => required fields, e.g. Product: [sku, name, price, image]')
                            ->useAttributeAsKey('class')->normalizeKeys(false)
                            ->arrayPrototype()->scalarPrototype()->end()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('copilot')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('api_key')->defaultValue('%env(string:default::ANTHROPIC_API_KEY)%')->end()
                        ->scalarNode('model')->defaultValue('claude-opus-5-5')->end()
                        ->integerNode('max_tokens')->defaultValue(1024)->min(1)->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
