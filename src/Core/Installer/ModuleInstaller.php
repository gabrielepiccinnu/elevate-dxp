<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Installer;

/**
 * Database schema and admin permissions contributed by one module.
 *
 * Implementations are collected by {@see Installer} (tag `elevate_dxp.module_installer`).
 * The initial schema is idempotent DDL; later changes ship as Doctrine migrations.
 */
abstract class ModuleInstaller
{
    public const TAG = 'elevate_dxp.module_installer';

    /**
     * @return list<string> permission keys registered under the "Elevate DXP" category
     */
    public function getPermissions(): array
    {
        return [];
    }

    /**
     * @return list<string> idempotent DDL statements (CREATE TABLE IF NOT EXISTS ...)
     */
    public function getSchema(): array
    {
        return [];
    }
}
