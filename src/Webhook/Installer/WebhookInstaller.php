<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Installer;

use ElevateDxp\Core\Installer\ModuleInstaller;
use ElevateDxp\Webhook\Repository\WebhookDeliveryRepository;

final class WebhookInstaller extends ModuleInstaller
{
    public const PERMISSION = 'elevate_dxp_webhook';

    public function getPermissions(): array
    {
        return [self::PERMISSION];
    }

    public function getSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS `'.WebhookDeliveryRepository::TABLE.'` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `subscription` VARCHAR(190) NOT NULL,
                `event` VARCHAR(64) NOT NULL,
                `url` VARCHAR(500) NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'failed\',
                `success` TINYINT(1) NOT NULL DEFAULT 0,
                `http_status` SMALLINT UNSIGNED NULL,
                `error` VARCHAR(500) NULL,
                `payload_json` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_subscription` (`subscription`),
                KEY `idx_status` (`status`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }

    /** @return list<string> exposed for the install command and tests */
    public function schemaStatements(): array
    {
        return $this->getSchema();
    }
}
