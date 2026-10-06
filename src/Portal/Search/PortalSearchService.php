<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Portal search facade: picks the configured {@see SearchBackendInterface} and keeps the legacy
 * convenience API (searchAssets / searchDataObjects) with the same result shape
 * {items,total,page,pageSize}.
 */
final class PortalSearchService
{
    /** @var array<string,SearchBackendInterface> */
    private array $backends = [];

    /** @param iterable<SearchBackendInterface> $backends */
    public function __construct(
        #[AutowireIterator(SearchBackendInterface::TAG)] iterable $backends,
        private readonly string $backendName = ListingSearchBackend::NAME,
        private readonly int $defaultPageSize = 24,
        private readonly int $maxPageSize = 100,
        private readonly string $defaultClass = 'Product',
    ) {
        foreach ($backends as $backend) {
            $this->backends[$backend->getName()] = $backend;
        }
    }

    public function search(SearchQuery $query): SearchResult
    {
        return $this->backendFor($query)->search($query);
    }

    /** Builds a query from loose input (admin filters, saved views) with the configured bounds. */
    public function queryFromArray(array $params): SearchQuery
    {
        return SearchQuery::fromArray($params, $this->defaultPageSize, $this->maxPageSize, $this->defaultClass);
    }

    public function backendFor(SearchQuery $query): SearchBackendInterface
    {
        $preferred = $this->backends[$this->backendName] ?? null;
        if ($preferred !== null && $preferred->supports($query)) {
            return $preferred;
        }
        foreach ($this->backends as $backend) {
            if ($backend->supports($query)) {
                return $backend;
            }
        }

        throw new \InvalidArgumentException('No portal search backend supports this query.');
    }

    /** @return list<string> */
    public function backendNames(): array
    {
        return array_keys($this->backends);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int} */
    public function searchAssets(?string $query = null, int $page = 1, int $pageSize = 24): array
    {
        return $this->search(new SearchQuery(
            SearchQuery::TYPE_ASSET,
            $query !== null && trim($query) !== '' ? trim($query) : null,
            page: max(1, $page),
            pageSize: max(1, min($this->maxPageSize, $pageSize)),
        ))->toArray();
    }

    /**
     * @param array<string,string> $orderBy field => asc|desc
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
     */
    public function searchDataObjects(
        string $className,
        ?string $query = null,
        int $page = 1,
        int $pageSize = 24,
        array $orderBy = [],
        ?float $minPrice = null,
        ?float $maxPrice = null,
        string $priceField = 'price',
    ): array {
        $order = [];
        foreach ($orderBy as $field => $dir) {
            $order[(string) $field] = strtolower((string) $dir) === 'desc' ? 'DESC' : 'ASC';
        }

        return $this->search(new SearchQuery(
            SearchQuery::TYPE_OBJECT,
            $query !== null && trim($query) !== '' ? trim($query) : null,
            $className,
            $minPrice !== null || $maxPrice !== null ? [new NumericRange($priceField, $minPrice, $maxPrice)] : [],
            orderBy: $order,
            page: max(1, $page),
            pageSize: max(1, min($this->maxPageSize, $pageSize)),
        ))->toArray();
    }
}
