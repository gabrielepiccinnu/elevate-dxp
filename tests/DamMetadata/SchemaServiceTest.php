<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\DamMetadata;

use ElevateDxp\DamMetadata\Metadata\SchemaService;
use PHPUnit\Framework\TestCase;

/** Ported from OpenPimcore\Tests\DamMetadataBundle\SchemaServiceTest, plus the new planning logic. */
final class SchemaServiceTest extends TestCase
{
    private function service(): SchemaService
    {
        return new SchemaService([
            'product_assets' => [
                'label' => 'Product assets',
                'path_prefix' => '/products',
                'asset_types' => ['image'],
                'fields' => [
                    ['name' => 'copyright', 'type' => 'input', 'default' => '(c) ACME'],
                    ['name' => 'usage_rights', 'type' => 'select', 'options' => ['web', 'print', 'all'], 'label' => 'Usage rights'],
                    ['name' => 'reviewed', 'type' => 'checkbox', 'default' => 'false'],
                    ['name' => 'priority', 'type' => 'number'],
                    ['name' => 'expires', 'type' => 'date'],
                ],
            ],
            'any' => ['fields' => [['name' => 'note', 'type' => 'textarea']]],
        ]);
    }

    public function testFieldLookup(): void
    {
        self::assertSame('select', $this->service()->field('product_assets', 'usage_rights')['type']);
        self::assertNull($this->service()->field('product_assets', 'nope'));
    }

    public function testValidateSelect(): void
    {
        $s = $this->service();
        $field = $s->field('product_assets', 'usage_rights');
        self::assertNull($s->validateValue($field, 'web'));
        self::assertNotNull($s->validateValue($field, 'bogus'));
    }

    public function testValidateNumberAndCheckbox(): void
    {
        $s = $this->service();
        self::assertNull($s->validateValue(['name' => 'priority', 'type' => 'number'], '5'));
        self::assertNotNull($s->validateValue(['name' => 'priority', 'type' => 'number'], 'x'));
        self::assertNull($s->validateValue(['name' => 'reviewed', 'type' => 'checkbox'], '1'));
        self::assertNotNull($s->validateValue(['name' => 'reviewed', 'type' => 'checkbox'], 'maybe'));
    }

    public function testPimcoreTypeMapping(): void
    {
        $s = $this->service();
        self::assertSame('input', $s->pimcoreType('number'));
        self::assertSame('select', $s->pimcoreType('select'));
        self::assertSame('checkbox', $s->pimcoreType('checkbox'));
        self::assertSame('input', $s->pimcoreType('unknown'));
        self::assertSame('date', $s->nativeType('date'));
    }

    public function testValidateDateAndNonScalar(): void
    {
        $s = $this->service();
        self::assertNull($s->validateValue(['name' => 'd', 'type' => 'date'], '2026-01-31'));
        self::assertNotNull($s->validateValue(['name' => 'd', 'type' => 'date'], 'not a date'));
        self::assertNotNull($s->validateValue(['name' => 'd', 'type' => 'input'], ['x']));
    }

    public function testNormalizeValue(): void
    {
        $s = $this->service();
        self::assertTrue($s->normalizeValue(['type' => 'checkbox'], 'true'));
        self::assertFalse($s->normalizeValue(['type' => 'checkbox'], '0'));
        self::assertSame(strtotime('2026-01-31'), $s->normalizeValue(['type' => 'date'], '2026-01-31'));
        self::assertSame('5', $s->normalizeValue(['type' => 'number'], 5));
    }

    public function testPathMatches(): void
    {
        $s = $this->service();
        self::assertTrue($s->pathMatches('product_assets', '/products/chair.jpg', 'image'));
        self::assertFalse($s->pathMatches('product_assets', '/products/manual.pdf', 'document'));
        self::assertFalse($s->pathMatches('product_assets', '/other/chair.jpg', 'image'));
        self::assertFalse($s->pathMatches('product_assets', '/products/sub', 'folder'));
        self::assertTrue($s->pathMatches('any', '/x/y.pdf', 'document'));
        self::assertFalse($s->pathMatches('missing', '/x', 'image'));
    }

    public function testPlanSingleField(): void
    {
        $s = $this->service();
        self::assertSame([['name' => 'usage_rights', 'type' => 'select', 'data' => 'web']],
            $s->plan('product_assets', [], 'usage_rights', 'web', false));
        self::assertSame([], $s->plan('product_assets', ['usage_rights'], 'usage_rights', 'web', false), 'no overwrite');
        self::assertCount(1, $s->plan('product_assets', ['usage_rights'], 'usage_rights', 'web', true));
    }

    public function testPlanDefaults(): void
    {
        $plan = $this->service()->plan('product_assets', ['copyright'], null, null, false);
        self::assertSame([['name' => 'reviewed', 'type' => 'checkbox', 'data' => false]], $plan);
    }

    public function testPlanRejectsInvalidInput(): void
    {
        $s = $this->service();
        foreach ([['nope', 'copyright', 'x'], ['product_assets', 'nope', 'x'], ['product_assets', 'usage_rights', 'tv']] as [$schema, $field, $value]) {
            try {
                $s->plan($schema, [], $field, $value, true);
                self::fail("expected rejection for $schema.$field=$value");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPredefinedDefinitions(): void
    {
        $defs = $this->service()->predefinedDefinitions('product_assets');
        self::assertCount(5, $defs);
        self::assertSame('Product assets', $defs[0]['group']);
        self::assertSame('image', $defs[0]['targetSubtype']);
        self::assertSame('web,print,all', $defs[1]['config']);
        self::assertSame('Usage rights', $defs[1]['description']);
        self::assertSame('input', $defs[3]['type']);

        $any = $this->service()->predefinedDefinitions('any');
        self::assertNull($any[0]['targetSubtype']);
        self::assertSame('any', $any[0]['group']);
        self::assertSame([], $this->service()->predefinedDefinitions('missing'));
    }

    public function testMissingDefinitions(): void
    {
        $s = $this->service();
        $defs = $s->predefinedDefinitions('product_assets');
        $missing = $s->missingDefinitions($defs, [
            ['name' => 'copyright', 'targetSubtype' => ''],       // generic covers image
            ['name' => 'usage_rights', 'targetSubtype' => 'image'],
            ['name' => 'reviewed', 'targetSubtype' => 'video'],   // other subtype does not count
        ]);
        self::assertSame(['reviewed', 'priority', 'expires'], array_column($missing, 'name'));
    }
}
