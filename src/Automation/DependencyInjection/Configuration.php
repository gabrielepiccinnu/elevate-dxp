<?php

declare(strict_types=1);

namespace ElevateDxp\Automation\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('automation');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()
                    ->info('When false the admin resource lists nothing and blueprint generation is refused.')->end()
            ->end();

        return $tb;
    }
}
