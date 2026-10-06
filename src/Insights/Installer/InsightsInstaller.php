<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

final class InsightsInstaller extends ModuleInstaller
{
    public const CDP = 'elevate_dxp_cdp';
    public const DATA_QUALITY = 'elevate_dxp_data_quality';
    public const COPILOT = 'elevate_dxp_copilot';

    public function getPermissions(): array
    {
        return [self::CDP, self::DATA_QUALITY, self::COPILOT];
    }
}
