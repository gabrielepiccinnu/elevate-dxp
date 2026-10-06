<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('sso');
        $tb->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()
                    ->info('Disabled by default; enabling requires a configured OIDC provider and login integration.')->end()
                ->scalarNode('groups_claim')->defaultValue('groups')
                    ->info('ID-token claim that carries the user groups used for role mapping.')->end()
                ->scalarNode('identifier_claim')->defaultValue('sub')
                    ->info('Claim used as the OpenDXP user name (falls back to "email").')->end()
                ->booleanNode('jit_provisioning')->defaultFalse()
                    ->info('Just-in-time user creation; off by default (only existing users may log in).')->end()
                ->arrayNode('providers')
                    ->useAttributeAsKey('name')->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')->values(['oidc'])->defaultValue('oidc')->end()
                            ->scalarNode('issuer')->defaultValue('')->end()
                            ->scalarNode('client_id')->defaultValue('')->end()
                            ->scalarNode('client_secret')->defaultValue('')->end()
                            ->arrayNode('scopes')->scalarPrototype()->end()
                                ->defaultValue(['openid', 'email', 'profile', 'groups'])->end()
                            ->arrayNode('role_mapping')
                                ->useAttributeAsKey('name')->normalizeKeys(false)
                                ->scalarPrototype()->end()
                                ->info('IdP group => OpenDXP role name, or "admin" for the admin flag (deny-by-default: unmapped groups grant nothing).')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}
