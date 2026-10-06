<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Search;

use ElevateDxp\Portal\Search\Listing\ConditionBuilder;
use PHPUnit\Framework\TestCase;

final class ConditionBuilderTest extends TestCase
{
    public function testEmptyBuilderYieldsNoCondition(): void
    {
        self::assertSame(['', []], (new ConditionBuilder())->text(['name'], '  ')->build());
    }

    public function testEveryTermMustMatchOneField(): void
    {
        [$sql, $params] = (new ConditionBuilder())->text(['name', 'sku'], 'red  shoe')->build();
        self::assertSame('(`name` LIKE ? OR `sku` LIKE ?) AND (`name` LIKE ? OR `sku` LIKE ?)', $sql);
        self::assertSame(['%red%', '%red%', '%shoe%', '%shoe%'], $params);
    }

    public function testLikeWildcardsAreEscaped(): void
    {
        [, $params] = (new ConditionBuilder())->text(['name'], '50%_off\\')->build();
        self::assertSame(['%50\\%\\_off\\\\%'], $params);
    }

    public function testRawClausesAreAddedPerTerm(): void
    {
        [$sql, $params] = (new ConditionBuilder())->text(['filename'], 'logo', ['`id` IN (SELECT cid FROM m WHERE data LIKE ?)'])->build();
        self::assertSame('(`filename` LIKE ? OR `id` IN (SELECT cid FROM m WHERE data LIKE ?))', $sql);
        self::assertSame(['%logo%', '%logo%'], $params);
    }

    public function testRangesAndPathAndFolders(): void
    {
        [$sql, $params] = (new ConditionBuilder())
            ->notEquals('type', 'folder')
            ->range('price', 10.0, null)
            ->range('weight', null, 2.5)
            ->pathPrefix('path', 'products/shoes/')
            ->build();
        self::assertSame('`type` != ? AND `price` >= ? AND `weight` <= ? AND `path` LIKE ?', $sql);
        self::assertSame(['folder', 10.0, 2.5, '/products/shoes/%'], $params);
    }

    public function testRootPathIsNoRestriction(): void
    {
        self::assertSame(['', []], (new ConditionBuilder())->pathPrefix('path', '/')->build());
    }

    public function testAnyPathPrefixDeniesWhenEmpty(): void
    {
        self::assertSame(['1 = 0', []], (new ConditionBuilder())->anyPathPrefix('path', [])->build());
        [$sql, $params] = (new ConditionBuilder())->anyPathPrefix('path', ['/a', '/b/'])->build();
        self::assertSame('(`path` LIKE ? OR `path` LIKE ?)', $sql);
        self::assertSame(['/a/%', '/b/%'], $params);
    }

    public function testTermsAreBounded(): void
    {
        self::assertCount(ConditionBuilder::MAX_TERMS, ConditionBuilder::terms(implode(' ', range(1, 20))));
    }

    /** @return iterable<array{string}> */
    public static function badIdentifiers(): iterable
    {
        yield ['name`; DROP TABLE x; --'];
        yield ['a b'];
        yield ['1abc'];
        yield [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIdentifiers')]
    public function testRejectsInjectedIdentifiers(string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConditionBuilder())->text([$field], 'x');
    }
}
