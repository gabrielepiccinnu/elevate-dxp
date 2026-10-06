<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** Endpoints are YAML-defined (GitOps): no tables, only the admin permission. */
final class DatahubInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_datahub';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
