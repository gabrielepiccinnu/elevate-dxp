<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\DependencyInjection;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\AdminResourceRegistry;
use ElevateDxp\DependencyInjection\Configuration;
use ElevateDxp\DependencyInjection\Modules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testEmptyConfigurationYieldsDefaultsForEveryModule(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        foreach (Modules::all() as $module) {
            self::assertArrayHasKey($module->key(), $config, $module->key());
        }
        self::assertSame('edxp_vid', $config['experiments']['visitor']['cookie_name']);
    }

    public function testUserDefinedMapKeysKeepHyphens(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'sso' => ['providers' => ['corp-idp' => ['type' => 'oidc', 'role_mapping' => ['dxp-admins' => 'admin']]]],
            'feed' => ['feeds' => ['google-shopping' => ['source' => ['type' => 'data_object', 'class' => 'Product'], 'template' => 'generic_csv']]],
        ]]);
        self::assertArrayHasKey('corp-idp', $config['sso']['providers']);
        self::assertSame(['dxp-admins' => 'admin'], $config['sso']['providers']['corp-idp']['role_mapping']);
        self::assertArrayHasKey('google-shopping', $config['feed']['feeds']);
    }

    public function testDisabledModulesAreHiddenFromTheAdminRegistry(): void
    {
        $registry = new AdminResourceRegistry([new FakeResource()], ['Tests' => false]);
        self::assertSame([], $registry->all());

        $registry = new AdminResourceRegistry([new FakeResource()], ['Tests' => true]);
        self::assertTrue($registry->has('fake'));
    }
}

final class FakeResource extends AbstractAdminResource
{
    public function getKey(): string
    {
        return 'fake';
    }

    public function getLabel(): string
    {
        return 'Fake';
    }

    public function getPermission(): string
    {
        return 'fake';
    }

    public function getSchema(): array
    {
        return [];
    }
}
