<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('workflow');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('storage_dir')->defaultValue('var/elevate-dxp/workflows')
                    ->info('Directory (relative to the project dir) where workflow definitions are stored as YAML.')->end()
                ->scalarNode('apply_dir')->defaultValue('config/local')
                    ->info('Directory (relative to the project dir) receiving the generated opendxp.workflows files; it must be imported by config/config.yaml.')->end()
                ->scalarNode('mermaid_render_url')->defaultValue('https://mermaid.ink/svg/')
                    ->info('Server-side Mermaid renderer used by the admin preview (the diagram source is sent to it). null = show source + link only.')->end()
            ->end();

        return $tb;
    }
}
