<?php

declare(strict_types=1);

namespace ElevateDxp\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * `elevate_dxp:` root; every module contributes one child tree (see Modules::all()).
 *
 *     bin/console config:dump-reference elevate_dxp
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('elevate_dxp');
        $children = $tb->getRootNode()->addDefaultsIfNotSet()->children();
        foreach (Modules::all() as $module) {
            $node = $module->configuration()->getConfigTreeBuilder()->getRootNode();
            if ($node instanceof ArrayNodeDefinition) {
                $node->addDefaultsIfNotSet();
            }
            $children->append($node);
        }

        return $tb;
    }
}
