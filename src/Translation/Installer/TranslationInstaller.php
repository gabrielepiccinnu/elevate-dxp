<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** No tables: providers are configured in YAML. */
final class TranslationInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_translation';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
