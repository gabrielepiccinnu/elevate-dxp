<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search;

/** Backend-neutral search result page. Items are plain arrays (id, type, key, fullPath, ...). */
final class SearchResult
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'page' => $this->page, 'pageSize' => $this->pageSize];
    }
}
