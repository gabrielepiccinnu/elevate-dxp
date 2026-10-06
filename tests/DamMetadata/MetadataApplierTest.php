<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\DamMetadata;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\DamMetadata\Admin\DamMetadataSchemasResource;
use ElevateDxp\DamMetadata\Metadata\MetadataApplier;
use ElevateDxp\DamMetadata\Metadata\SchemaService;
use PHPUnit\Framework\TestCase;

final class MetadataApplierTest extends TestCase
{
    public function testNormalizeFolderPath(): void
    {
        self::assertSame('/', MetadataApplier::normalizeFolderPath(''));
        self::assertSame('/', MetadataApplier::normalizeFolderPath('/'));
        self::assertSame('/a/b', MetadataApplier::normalizeFolderPath('a/b/'));
        self::assertSame('/a/b', MetadataApplier::normalizeFolderPath(' /a/b '));
    }

    public function testNormalizeFolderPathRejectsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MetadataApplier::normalizeFolderPath('/a/../b');
    }

    public function testLikePrefixEscapesWildcards(): void
    {
        self::assertSame('/%', MetadataApplier::likePrefix('/'));
        self::assertSame('/products/%', MetadataApplier::likePrefix('/products'));
        self::assertSame('/a\_b\%c/%', MetadataApplier::likePrefix('/a_b%c'));
    }

    public function testInvalidInputFailsBeforeTouchingAssets(): void
    {
        $applier = new MetadataApplier(
            new SchemaService(['s' => ['fields' => [['name' => 'f', 'type' => 'number']]]]),
            $this->createStub(AuditLoggerInterface::class),
        );
        self::assertSame("Unknown schema 'x'.", $applier->applyToFolder('/', 'x', 'f', '1')['error']);
        self::assertStringContainsString('numeric', (string) $applier->applyToFolder('/', 's', 'f', 'abc')['error']);
        self::assertStringContainsString('Invalid folder', (string) $applier->applyToFolder('/a/../b', 's', 'f', '1')['error']);
        self::assertSame("Unknown field 'g' in schema 's'.", $applier->apply('s', 'g', '1')['error'], 'legacy apply() entry point');
    }

    public function testSummary(): void
    {
        self::assertSame('Matched 3 asset(s): updated 1, unchanged 1, denied 1, failed 0.',
            DamMetadataSchemasResource::summary(['matched' => 3, 'updated' => 1, 'unchanged' => 1, 'denied' => 1, 'failed' => 0]));
    }
}
