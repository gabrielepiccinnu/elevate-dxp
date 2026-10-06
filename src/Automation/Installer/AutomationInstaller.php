<?php

declare(strict_types=1);

namespace ElevateDxp\Automation\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** No tables: blueprints are computed from the webhook configuration. Only the permission is registered. */
final class AutomationInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_automation';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
