<?php

declare(strict_types=1);

namespace EzPhp\Search;

/**
 * Class SearchResult
 *
 * Immutable value object representing the result of a search query.
 *
 * @package EzPhp\Search
 */
final readonly class SearchResult
{
    /**
     * SearchResult Constructor
     *
     * @param list<SearchHit> $hits  The matched documents for the current page.
     * @param int             $total Total number of matching documents (across all pages).
     * @param int             $took  Time the search engine spent processing the query, in milliseconds.
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $took,
    ) {
    }

    /**
     * Return true when no documents matched the query.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
