<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Installer;

final class CoreInstaller extends ModuleInstaller
{
    public function getPermissions(): array
    {
        return ['elevate_dxp_admin'];
    }
}
