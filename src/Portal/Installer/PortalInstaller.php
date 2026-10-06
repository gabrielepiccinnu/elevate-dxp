<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

final class PortalInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_portal';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }

    public function getSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS edxp_portal_collection (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner VARCHAR(190) NOT NULL,
                name VARCHAR(190) NOT NULL,
                share_token CHAR(40) NULL,
                share_expires_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                KEY idx_owner (owner),
                UNIQUE KEY uniq_share_token (share_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS edxp_portal_collection_item (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                collection_id INT UNSIGNED NOT NULL,
                element_type VARCHAR(16) NOT NULL,
                element_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_item (collection_id, element_type, element_id),
                KEY idx_collection (collection_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS edxp_portal_saved_view (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner VARCHAR(190) NOT NULL,
                name VARCHAR(190) NOT NULL,
                params_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_owner (owner)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
