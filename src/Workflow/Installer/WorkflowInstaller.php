<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** No tables: definitions live in YAML files (GitOps-friendly). Only the permission is registered. */
final class WorkflowInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_workflow';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
