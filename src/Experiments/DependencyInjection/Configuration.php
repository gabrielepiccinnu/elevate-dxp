<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('experiments');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('visitor')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cookie_name')->defaultValue('edxp_vid')->end()
                        ->integerNode('cookie_ttl_days')->defaultValue(365)->min(1)->end()
                        ->scalarNode('secret')->defaultValue('%kernel.secret%')->end()
                        ->scalarNode('consent_cookie')
                            ->info('If set, no visitor id is issued (and nothing is tracked) unless this cookie exists, e.g. your CMP consent cookie.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('excluded_paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['^/admin', '^/_', '^/bundles/', '^/elevate-dxp/'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('profile')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->info('Keep a lightweight first-party visitor profile (visits, UTM, last URL).')->end()
                    ->end()
                ->end()
                ->arrayNode('runtime')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('inject')->defaultTrue()->info('Inject the edxp runtime script into HTML responses.')->end()
                    ->end()
                ->end()
                ->arrayNode('tracking')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->arrayNode('allowed_events')
                            ->info('Allow-list of event names accepted by /_edxp/track. Empty = any name matching [a-z0-9_.:-]{1,64}.')
                            ->scalarPrototype()->end()
                        ->end()
                        ->integerNode('max_payload_bytes')->defaultValue(4096)->end()
                        ->integerNode('rate_limit_per_minute')->defaultValue(60)->end()
                    ->end()
                ->end()
                ->arrayNode('analytics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('adapter')->values(['none', 'matomo', 'posthog'])->defaultValue('none')->end()
                        ->arrayNode('matomo')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('url')->defaultValue('')->end()
                                ->scalarNode('site_id')->defaultValue('1')->end()
                                ->scalarNode('token')->defaultValue('')->end()
                            ->end()
                        ->end()
                        ->arrayNode('posthog')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('host')->defaultValue('https://eu.i.posthog.com')->end()
                                ->scalarNode('api_key')->defaultValue('')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
