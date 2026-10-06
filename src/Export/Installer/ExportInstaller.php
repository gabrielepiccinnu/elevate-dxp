<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** Export jobs are YAML-defined (GitOps): no tables, only the admin permission. */
final class ExportInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_export';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
