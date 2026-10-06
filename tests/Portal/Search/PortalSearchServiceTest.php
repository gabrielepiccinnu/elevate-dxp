<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Search;

use ElevateDxp\Portal\Search\PortalSearchService;
use ElevateDxp\Portal\Search\SearchBackendInterface;
use ElevateDxp\Portal\Search\SearchQuery;
use ElevateDxp\Portal\Search\SearchResult;
use PHPUnit\Framework\TestCase;

final class PortalSearchServiceTest extends TestCase
{
    private function backend(string $name, bool $objects, ?SearchQuery &$seen = null): SearchBackendInterface
    {
        return new class($name, $objects, $seen) implements SearchBackendInterface {
            public function __construct(private readonly string $name, private readonly bool $objects, public ?SearchQuery &$seen)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function supports(SearchQuery $query): bool
            {
                return $query->type === 'asset' || $this->objects;
            }

            public function search(SearchQuery $query): SearchResult
            {
                $this->seen = $query;

                return new SearchResult([['id' => 1, 'by' => $this->name]], 1, $query->page, $query->pageSize);
            }
        };
    }

    public function testUsesConfiguredBackendAndFallsBack(): void
    {
        $service = new PortalSearchService([$this->backend('listing', false), $this->backend('advanced', true)], 'listing');
        self::assertSame(['listing', 'advanced'], $service->backendNames());

        self::assertSame('listing', $service->searchAssets('x')['items'][0]['by']);
        // "listing" (stub) does not support objects here → falls back to "advanced".
        self::assertSame('advanced', $service->searchDataObjects('Product', 'x')['items'][0]['by']);
    }

    public function testUnknownConfiguredBackendFallsBackToAnySupporting(): void
    {
        $service = new PortalSearchService([$this->backend('listing', true)], 'does-not-exist');
        self::assertSame('listing', $service->searchAssets()['items'][0]['by']);
    }

    public function testNoSupportingBackendIsAUserError(): void
    {
        $service = new PortalSearchService([$this->backend('listing', false)]);
        $this->expectException(\InvalidArgumentException::class);
        $service->searchDataObjects('Product');
    }

    public function testLegacyApiMapsPriceRangeOrderAndPageSizeBound(): void
    {
        $seen = null;
        $service = new PortalSearchService([$this->backend('listing', true, $seen)], 'listing', 24, 50);
        $out = $service->searchDataObjects('Product', ' boot ', 2, 500, ['name' => 'desc'], 10.0, null, 'price');

        self::assertSame(['items', 'total', 'page', 'pageSize'], array_keys($out));
        self::assertSame('boot', $seen->text);
        self::assertSame('Product', $seen->className);
        self::assertSame('price', $seen->ranges[0]->field);
        self::assertSame(10.0, $seen->ranges[0]->min);
        self::assertSame(['name' => 'DESC'], $seen->orderBy);
        self::assertSame(50, $seen->pageSize);
        self::assertSame(2, $seen->page);
    }

    public function testQueryFromArrayUsesDefaults(): void
    {
        $service = new PortalSearchService([], 'listing', 12, 40, 'Car');
        $q = $service->queryFromArray(['type' => 'object', 'limit' => 0]);
        self::assertSame('Car', $q->className);
        self::assertSame(12, $q->pageSize);
    }
}
