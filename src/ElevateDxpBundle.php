<?php

declare(strict_types=1);

/*
 * Elevate DXP — marketing, integration and content extensions for OpenDXP.
 * GPL-3.0-or-later, see LICENSE.
 */

namespace ElevateDxp;

use ElevateDxp\Core\Installer\Installer;
use OpenDxp\Bundle\AdminBundle\OpenDxpAdminBundle;
use OpenDxp\Bundle\PersonalizationBundle\OpenDxpPersonalizationBundle;
use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use OpenDxp\Extension\Bundle\Installer\InstallerInterface;
use OpenDxp\Extension\Bundle\OpenDxpBundleAdminClassicInterface;
use OpenDxp\Extension\Bundle\Traits\BundleAdminClassicTrait;
use OpenDxp\Extension\Bundle\Traits\PackageVersionTrait;
use OpenDxp\HttpKernel\Bundle\DependentBundleInterface;
use OpenDxp\HttpKernel\BundleCollection\BundleCollection;

final class ElevateDxpBundle extends AbstractOpenDxpBundle implements OpenDxpBundleAdminClassicInterface, DependentBundleInterface
{
    use BundleAdminClassicTrait;
    use PackageVersionTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getNiceName(): string
    {
        return 'Elevate DXP';
    }

    public function getDescription(): string
    {
        return 'A/B testing, conversion tracking, visitor insights, product feeds, datahub, webhooks, portal and workflow designer for OpenDXP.';
    }

    protected function getComposerPackageName(): string
    {
        return 'elevate-dxp/elevate-bundle';
    }

    public function getInstaller(): InstallerInterface
    {
        return $this->container->get(Installer::class);
    }

    public function getJsPaths(): array
    {
        return [
            '/bundles/elevatedxp/js/elevatedxp.js',
            '/bundles/elevatedxp/js/fields.js',
            '/bundles/elevatedxp/js/action-runner.js',
            '/bundles/elevatedxp/js/crud-panel.js',
            '/bundles/elevatedxp/js/report-panel.js',
            '/bundles/elevatedxp/js/startup.js',
            '/bundles/elevatedxp/js/admin/targeting.js',
        ];
    }

    public function getCssPaths(): array
    {
        return ['/bundles/elevatedxp/css/elevatedxp.css'];
    }

    public static function registerDependentBundles(BundleCollection $collection): void
    {
        $collection->addBundle(new OpenDxpAdminBundle(), 60);
        $collection->addBundle(new OpenDxpPersonalizationBundle());
    }
}
