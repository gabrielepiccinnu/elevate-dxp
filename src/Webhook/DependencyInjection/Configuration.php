<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\DependencyInjection;

use ElevateDxp\Webhook\Webhook\WebhookEvents;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('webhook');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->booleanNode('sink_enabled')->defaultFalse()
                    ->info('Local debug receiver at POST /elevate-dxp/webhook-sink (dev/testing only, never in production).')->end()
                ->scalarNode('sink_secret')->defaultValue('')
                    ->info('Optional: when set, the sink verifies X-ElevateDxp-Signature and answers 401 on mismatch.')->end()
                ->integerNode('timeout')->defaultValue(5)->min(1)->max(60)->end()
                ->arrayNode('subscriptions')
                    ->useAttributeAsKey('name')->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('active')->defaultTrue()->end()
                            ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('secret')->defaultValue('')->info('HMAC-SHA256 signing secret; use %env()%, never commit it.')->end()
                            ->arrayNode('events')
                                ->info(implode('|', WebhookEvents::ALL))
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifNotInArray(WebhookEvents::ALL)
                                        ->thenInvalid('Unknown webhook event %s. Allowed: '.implode(', ', WebhookEvents::ALL))
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
