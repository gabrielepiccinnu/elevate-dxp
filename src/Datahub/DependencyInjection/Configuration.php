<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('datahub');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('api_key_header')->defaultValue('X-Elevate-Dxp-Api-Key')->end()
                ->scalarNode('api_key')->defaultValue('')
                    ->info('Static API key; set it from an env var, e.g. "%env(ELEVATE_DXP_DATAHUB_API_KEY)%". Empty = all requests denied.')->end()
                ->integerNode('max_limit')->defaultValue(100)->min(1)->end()
                ->arrayNode('endpoints')
                    ->useAttributeAsKey('name')
                    ->validate()
                        ->ifTrue(static fn (array $eps): bool => array_filter(array_keys($eps), static fn ($k): bool => !preg_match('/^[A-Za-z0-9_-]+$/', (string) $k)) !== [])
                        ->thenInvalid('Endpoint names may only contain letters, digits, "_" and "-".')
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')->values(['data_object', 'asset'])->isRequired()->end()
                            ->scalarNode('class')->defaultNull()->info('Data object class name (type data_object).')->end()
                            ->scalarNode('path')->defaultNull()->info('Informational only; the endpoint is served at /elevate-dxp/api/{name}.')->end()
                            ->arrayNode('fields')
                                ->useAttributeAsKey('name')
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
