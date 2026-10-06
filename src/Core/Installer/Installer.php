<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Installer;

use Doctrine\DBAL\Connection;
use OpenDxp\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

/**
 * Bundle installer (`bin/console opendxp:bundle:install ElevateDxpBundle`).
 *
 * - creates every module's tables from idempotent DDL;
 * - registers the admin permissions under the "Elevate DXP" category;
 * - marks the bundle migrations as executed.
 *
 * Uninstall removes permissions but keeps data tables on purpose: dropping experiment results,
 * delivery logs or visitor data must be an explicit, manual decision.
 */
final class Installer extends SettingsStoreAwareInstaller
{
    public const PERMISSION_CATEGORY = 'Elevate DXP';

    /**
     * @param iterable<ModuleInstaller> $modules
     */
    public function __construct(
        BundleInterface $bundle,
        private readonly Connection $db,
        #[AutowireIterator(ModuleInstaller::TAG)] private readonly iterable $modules,
    ) {
        parent::__construct($bundle);
    }

    public function install(): void
    {
        $this->installSchema();
        $this->installPermissions();
        parent::install();
    }

    public function uninstall(): void
    {
        foreach ($this->permissions() as $key) {
            $this->db->delete('users_permission_definitions', [$this->db->quoteIdentifier('key') => $key]);
        }
        parent::uninstall();
    }

    /** Re-runnable: safe to call on every deployment (see `elevate-dxp:setup`). */
    public function installSchema(): void
    {
        foreach ($this->modules as $module) {
            foreach ($module->getSchema() as $ddl) {
                $this->db->executeStatement($ddl);
            }
        }
    }

    public function installPermissions(): void
    {
        foreach ($this->permissions() as $key) {
            $this->db->executeStatement(
                'INSERT INTO users_permission_definitions (`key`, `category`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `category` = VALUES(`category`)',
                [$key, self::PERMISSION_CATEGORY],
            );
        }
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $all = [];
        foreach ($this->modules as $module) {
            array_push($all, ...$module->getPermissions());
        }

        return array_values(array_unique($all));
    }
}
