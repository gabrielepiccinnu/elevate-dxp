<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Search;

use ElevateDxp\Portal\Search\NumericRange;
use ElevateDxp\Portal\Search\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function testFromArrayNormalisesAndBounds(): void
    {
        $q = SearchQuery::fromArray([
            'type' => 'object', 'q' => '  shoe ', 'min' => '10', 'max' => 99, 'range_field' => 'price',
            'order_by' => 'name', 'order_dir' => 'desc', 'start' => 50, 'limit' => 500,
        ], 24, 100, 'Product');

        self::assertSame('object', $q->type);
        self::assertSame('shoe', $q->text);
        self::assertSame('Product', $q->className);
        self::assertEquals([new NumericRange('price', 10.0, 99.0)], $q->ranges);
        self::assertSame(['name' => 'DESC'], $q->orderBy);
        self::assertSame(100, $q->pageSize);
        self::assertSame(1, $q->page);
        self::assertSame(0, $q->offset());
    }

    public function testStartLimitToPage(): void
    {
        $q = SearchQuery::fromArray(['start' => 50, 'limit' => 25]);
        self::assertSame(3, $q->page);
        self::assertSame(50, $q->offset());
        self::assertSame('asset', $q->type);
        self::assertNull($q->className);
        self::assertTrue($q->excludeFolders);
    }

    public function testRoundTrip(): void
    {
        $q = SearchQuery::fromArray(['type' => 'object', 'class' => 'Car', 'q' => 'red', 'path' => '/cars', 'ranges' => [['field' => 'hp', 'min' => 100]], 'order_by' => 'key']);
        $again = SearchQuery::fromArray($q->toArray());
        self::assertEquals($q, $again);
    }

    public function testRejectsNonNumericRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SearchQuery::fromArray(['min' => 'abc']);
    }

    public function testRejectsInvertedRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SearchQuery::fromArray(['min' => 10, 'max' => 1]);
    }

    public function testRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchQuery('document');
    }
}
