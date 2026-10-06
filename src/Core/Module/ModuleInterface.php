<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Module;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A feature module of the Elevate DXP bundle (experiments, feed, portal, ...).
 *
 * Each module owns one subtree of the `elevate_dxp` configuration (named after key()) and its
 * own service file in config/services/<key>.yaml. The bundle extension validates the whole
 * configuration once and hands every module its processed subtree.
 */
interface ModuleInterface
{
    /** Configuration key under `elevate_dxp`, e.g. "experiments". */
    public function key(): string;

    /** Tree whose root node name equals key(). */
    public function configuration(): ConfigurationInterface;

    public function prepend(ContainerBuilder $container): void;

    /**
     * @param array<string, mixed> $config processed configuration of this module
     */
    public function load(array $config, ContainerBuilder $container): void;
}
