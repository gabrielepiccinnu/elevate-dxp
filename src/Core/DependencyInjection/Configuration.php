<?php

declare(strict_types=1);

namespace ElevateDxp\Core\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('core');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('audit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('default_policy')->values(['deny', 'allow'])->defaultValue('deny')->end()
                        ->arrayNode('allowed_actions')->scalarPrototype()->end()->defaultValue([])->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
