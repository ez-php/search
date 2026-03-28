<?php

declare(strict_types=1);

namespace EzPhp\Search;

/**
 * Class SearchOptions
 *
 * Immutable value object carrying pagination, filter, and sort options for a
 * search query. All withers return a new instance — the original is unchanged.
 *
 * Usage:
 *   $options = (new SearchOptions())
 *       ->withLimit(10)
 *       ->withOffset(20)
 *       ->withFilter('status = "published"')
 *       ->withSort(['createdAt:desc']);
 *
 * @package EzPhp\Search
 */
final readonly class SearchOptions
{
    /**
     * SearchOptions Constructor
     *
     * @param int          $offset  Number of documents to skip (default 0).
     * @param int          $limit   Maximum number of documents to return (default 20).
     * @param string       $filter  Driver-specific filter expression (default empty = no filter).
     * @param list<string> $sort    List of sort descriptors, e.g. ["price:asc", "name:desc"].
     */
    public function __construct(
        public int $offset = 0,
        public int $limit = 20,
        public string $filter = '',
        public array $sort = [],
    ) {
    }

    /**
     * Return a copy with the given offset.
     *
     * @param int $offset
     *
     * @return self
     */
    public function withOffset(int $offset): self
    {
        return new self($offset, $this->limit, $this->filter, $this->sort);
    }

    /**
     * Return a copy with the given limit.
     *
     * @param int $limit
     *
     * @return self
     */
    public function withLimit(int $limit): self
    {
        return new self($this->offset, $limit, $this->filter, $this->sort);
    }

    /**
     * Return a copy with the given filter expression.
     *
     * The filter syntax is driver-specific:
     *  - Meilisearch: attribute-based filter, e.g. 'status = "published"'
     *  - Elasticsearch: passed as a filter query string
     *
     * @param string $filter
     *
     * @return self
     */
    public function withFilter(string $filter): self
    {
        return new self($this->offset, $this->limit, $filter, $this->sort);
    }

    /**
     * Return a copy with the given sort descriptors.
     *
     * @param list<string> $sort
     *
     * @return self
     */
    public function withSort(array $sort): self
    {
        return new self($this->offset, $this->limit, $this->filter, $sort);
    }
}
