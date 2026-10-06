<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('translation');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('provider')->defaultValue('pseudo')
                    ->info('Default machine-translation provider key (pseudo, libretranslate or a custom tagged provider). Unknown keys fall back to pseudo.')->end()
                ->arrayNode('languages')->scalarPrototype()->end()
                    ->defaultValue(['en', 'it', 'de', 'fr', 'es', 'pt', 'nl'])
                    ->info('Languages offered in the admin translate dialogs.')->end()
                ->arrayNode('libretranslate')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('url')->defaultValue('http://libretranslate:5000')
                            ->validate()
                                ->ifTrue(static fn ($v): bool => !\is_string($v) || !preg_match('#^https?://#i', $v))
                                ->thenInvalid('libretranslate.url must be an http(s) URL.')
                            ->end()
                        ->end()
                        ->scalarNode('api_key')->defaultValue('')->end()
                        ->integerNode('timeout')->defaultValue(10)->min(1)->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
