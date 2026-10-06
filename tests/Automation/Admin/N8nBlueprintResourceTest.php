<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Automation\Admin;

use ElevateDxp\Automation\Admin\N8nBlueprintResource;
use ElevateDxp\Automation\N8n\N8nBlueprintGenerator;
use PHPUnit\Framework\TestCase;

final class N8nBlueprintResourceTest extends TestCase
{
    private function resource(bool $enabled = true): N8nBlueprintResource
    {
        return new N8nBlueprintResource(new N8nBlueprintGenerator([
            'erp' => ['events' => ['object.update'], 'url' => 'http://erp', 'secret' => 'topsecret'],
            'crm' => ['events' => ['asset.add', 'asset.delete'], 'url' => 'http://crm'],
        ], $enabled));
    }

    public function testSchemaAndList(): void
    {
        $r = $this->resource();
        self::assertSame('automation_n8n', $r->getKey());
        self::assertSame('Integration', $r->getGroup());
        self::assertSame('elevate_dxp_automation', $r->getPermission());
        $schema = $r->getSchema();
        self::assertFalse($schema['canCreate'] || $schema['canEdit'] || $schema['canDelete']);
        self::assertSame('blueprint', $schema['actions'][0]['name']);
        self::assertSame('record', $schema['actions'][0]['scope']);

        $list = $r->list(['sort' => 'name']);
        self::assertSame(2, $list['total']);
        self::assertSame('crm', $list['data'][0]['name']);
        self::assertSame('asset.add, asset.delete', $list['data'][0]['events']);
        self::assertSame('elevate-dxp-crm', $list['data'][0]['n8n_path']);
        self::assertSame('erp', $r->get('erp')['name']);
        self::assertNull($r->get('nope'));
    }

    public function testGenerateBlueprintReturnsJsonText(): void
    {
        $result = $this->resource()->runAction('blueprint', 'erp', []);
        self::assertTrue($result['success']);
        $bp = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Elevate DXP · erp', $bp['name']);
        self::assertStringNotContainsString('topsecret', $result['text']);
    }

    public function testGenerateRequiresKnownSubscription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resource()->runAction('blueprint', 'nope', []);
    }

    public function testDisabled(): void
    {
        $r = $this->resource(false);
        self::assertSame(0, $r->list([])['total']);
        $this->expectException(\InvalidArgumentException::class);
        $r->runAction('blueprint', 'erp', []);
    }
}
