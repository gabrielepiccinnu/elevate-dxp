<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('portal');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('products_class')->defaultValue('Product')
                    ->info('Default DataObject class used by object searches when none is given.')->end()
                ->scalarNode('object_image_field')->defaultValue('image')
                    ->info('Object field whose asset is added to ZIP downloads for object items.')->end()
                ->arrayNode('allowed_asset_paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['/products'])
                    ->info('Deny-by-default: only assets below one of these folders can be downloaded or shown to guests.')
                ->end()
                ->arrayNode('share')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('ttl_days')->defaultValue(7)->min(1)->end()
                        ->integerNode('max_ttl_days')->defaultValue(90)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('search')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('backend')->defaultValue('listing')
                            ->info('Name of the search backend (SearchBackendInterface::getName()). "listing" ships by default.')->end()
                        ->integerNode('page_size')->defaultValue(24)->min(1)->end()
                        ->integerNode('max_page_size')->defaultValue(100)->min(1)->end()
                        ->arrayNode('asset_fields')
                            ->scalarPrototype()->end()
                            ->defaultValue(['filename', 'path'])
                            ->info('Columns of the assets table searched by the full-text LIKE.')
                        ->end()
                        ->booleanNode('asset_metadata')->defaultTrue()
                            ->info('Also match the text against asset metadata values (assets_metadata.data).')->end()
                        ->arrayNode('default_object_fields')
                            ->scalarPrototype()->end()
                            ->defaultValue(['key'])
                        ->end()
                        ->arrayNode('object_fields')
                            ->useAttributeAsKey('class')
                            ->arrayPrototype()->scalarPrototype()->end()->end()
                            ->defaultValue([])
                            ->info('Per-class searchable fields, e.g. {Product: [name, sku]}. Falls back to default_object_fields.')
                        ->end()
                        ->scalarNode('locale')->defaultNull()
                            ->info('Locale used for localized fields in object listings (null = default).')->end()
                        ->booleanNode('restrict_assets_to_allowed_paths')->defaultFalse()
                            ->info('Limit asset search results to allowed_asset_paths as well.')->end()
                    ->end()
                ->end()
                ->arrayNode('branding')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('portal_name')->defaultValue('Elevate DXP Portal')->end()
                        ->scalarNode('logo_url')->defaultNull()
                            ->info('Optional logo image URL; if null a text mark is shown.')->end()
                        ->scalarNode('primary_color')->defaultValue('#0e7c7b')->end()
                        ->scalarNode('accent_color')->defaultValue('#16242b')->end()
                        ->scalarNode('footer_text')->defaultValue('Elevate DXP portal · GPL-3.0-or-later')->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
