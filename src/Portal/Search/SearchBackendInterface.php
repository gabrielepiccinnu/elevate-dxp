<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search;

/**
 * Pluggable portal search engine.
 *
 * The default implementation is {@see ListingSearchBackend} (plain OpenDXP listings, SQL LIKE).
 * A richer engine (e.g. an AdvancedObjectSearch / OpenSearch based one) only has to implement this
 * interface; autoconfiguration tags it and `elevate_dxp_portal.search.backend: <name>` selects it.
 * When the selected backend does not support a query, the service falls back to any backend that does.
 */
interface SearchBackendInterface
{
    public const TAG = 'elevate_dxp_portal.search_backend';

    /** Short, unique name used in configuration (e.g. "listing"). */
    public function getName(): string;

    public function supports(SearchQuery $query): bool;

    public function search(SearchQuery $query): SearchResult;
}
