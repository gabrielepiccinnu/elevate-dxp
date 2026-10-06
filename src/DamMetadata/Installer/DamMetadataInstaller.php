<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** No tables: schemas live in YAML, values in native asset metadata. */
final class DamMetadataInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_dam_metadata';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
