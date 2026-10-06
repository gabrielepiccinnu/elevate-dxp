<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;

final class ExperimentsInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_experiments';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }

    public function getSchema(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS edxp_experiment (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exp_key VARCHAR(100) NOT NULL,
                name VARCHAR(190) NOT NULL,
                description TEXT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                traffic INT NOT NULL DEFAULT 100,
                goal_event VARCHAR(100) NULL,
                target_group_id INT NULL,
                url_pattern VARCHAR(255) NULL,
                variants LONGTEXT NULL,
                start_at DATETIME NULL,
                end_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_exp_key (exp_key),
                KEY idx_status (status)
            ) DEFAULT CHARSET=utf8mb4
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS edxp_assignment (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                visitor_id VARCHAR(64) NOT NULL,
                experiment_id INT NOT NULL,
                variant_key VARCHAR(100) NOT NULL,
                forced TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_assignment (visitor_id, experiment_id),
                KEY idx_experiment_variant (experiment_id, variant_key)
            ) DEFAULT CHARSET=utf8mb4
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS edxp_event (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                visitor_id VARCHAR(64) NOT NULL,
                event_name VARCHAR(64) NOT NULL,
                event_type VARCHAR(16) NOT NULL DEFAULT 'custom',
                event_value DECIMAL(14,4) NULL,
                url VARCHAR(500) NULL,
                experiment_key VARCHAR(100) NULL,
                variant_key VARCHAR(100) NULL,
                metadata_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_visitor (visitor_id),
                KEY idx_name_created (event_name, created_at),
                KEY idx_experiment (experiment_key)
            ) DEFAULT CHARSET=utf8mb4
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS edxp_visitor_profile (
                visitor_id VARCHAR(64) NOT NULL PRIMARY KEY,
                targeting_visitor_id VARCHAR(100) NULL,
                first_seen DATETIME NOT NULL,
                last_seen DATETIME NOT NULL,
                pageviews INT NOT NULL DEFAULT 0,
                sessions INT NOT NULL DEFAULT 0,
                first_url VARCHAR(500) NULL,
                last_url VARCHAR(500) NULL,
                referrer VARCHAR(500) NULL,
                utm_first LONGTEXT NULL,
                utm_last LONGTEXT NULL,
                target_groups LONGTEXT NULL,
                KEY idx_last_seen (last_seen)
            ) DEFAULT CHARSET=utf8mb4
            SQL,
        ];
    }
}
