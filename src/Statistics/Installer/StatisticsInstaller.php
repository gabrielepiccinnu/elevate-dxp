<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** No tables: reports live in YAML config (and optionally in native Custom Reports). */
final class StatisticsInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_statistics';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
