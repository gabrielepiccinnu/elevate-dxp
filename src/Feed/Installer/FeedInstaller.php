<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

/** Feeds are YAML-defined (GitOps): no tables, only the admin permission. */
final class FeedInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_feed';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }
}
