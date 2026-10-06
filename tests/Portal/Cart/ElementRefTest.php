<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Cart;

use ElevateDxp\Portal\Cart\ElementRef;
use ElevateDxp\Portal\Cart\ZipBuilder;
use PHPUnit\Framework\TestCase;

final class ElementRefTest extends TestCase
{
    public function testParsesTypedAndBareReferences(): void
    {
        self::assertSame(['type' => 'asset', 'id' => 12], ElementRef::parse('asset:12'));
        self::assertSame(['type' => 'object', 'id' => 7], ElementRef::parse('object:7'));
        self::assertSame(['type' => 'object', 'id' => 7], ElementRef::parse(' o#7 '));
        self::assertSame(['type' => 'asset', 'id' => 5], ElementRef::parse('5'));
    }

    public function testParseListDeduplicatesAndAcceptsSeparators(): void
    {
        self::assertSame(
            [['type' => 'asset', 'id' => 1], ['type' => 'object', 'id' => 2]],
            ElementRef::parseList("asset:1, object:2;\n1 a:1"),
        );
        self::assertSame([['type' => 'asset', 'id' => 3]], ElementRef::parseList(['asset:3']));
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ElementRef::parse('asset:1 OR 1=1');
    }

    public function testRejectsZeroId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ElementRef::parse('asset:0');
    }

    public function testZipNamesAreUniqueAndTraversalSafe(): void
    {
        $used = [];
        self::assertSame('a.jpg', ZipBuilder::uniqueName('a.jpg', 1, $used));
        self::assertSame('a-1.jpg', ZipBuilder::uniqueName('a.jpg', 2, $used));
        self::assertSame('a-2.jpg', ZipBuilder::uniqueName('a.jpg', 3, $used));
        self::assertSame('passwd', ZipBuilder::uniqueName('../../etc/passwd', 4, $used));
        self::assertSame('asset-9', ZipBuilder::uniqueName('', 9, $used));
        self::assertSame('collection-12', ZipBuilder::safe('collection 12'));
        self::assertSame('export', ZipBuilder::safe('***'));
    }
}
