<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

final class SsoInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_sso';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
