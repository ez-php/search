<?php

declare(strict_types=1);

namespace EzPhp\Search;

/**
 * Interface SearchDriverInterface
 *
 * Contract for search engine adapters.
 *
 * A driver translates the generic search operations (index, remove, search,
 * flush) into API calls for a specific search engine such as Meilisearch or
 * Elasticsearch.
 *
 * @package EzPhp\Search
 */
interface SearchDriverInterface
{
    /**
     * Add or replace a document in the given index.
     *
     * @param string          $indexName  The index to write to.
     * @param string|int      $id         Unique document identifier.
     * @param array<string, mixed> $document   The document data to store.
     *
     * @return void
     */
    public function index(string $indexName, string|int $id, array $document): void;

    /**
     * Remove a document from the given index.
     *
     * @param string     $indexName  The index to remove from.
     * @param string|int $id         Unique document identifier.
     *
     * @return void
     */
    public function remove(string $indexName, string|int $id): void;

    /**
     * Execute a full-text search query.
     *
     * @param string        $indexName  The index to search in.
     * @param string        $query      The search query string.
     * @param SearchOptions $options    Pagination, filters, and sort options.
     *
     * @return SearchResult
     */
    public function search(string $indexName, string $query, SearchOptions $options): SearchResult;

    /**
     * Remove all documents from the given index.
     *
     * @param string $indexName  The index to flush.
     *
     * @return void
     */
    public function flush(string $indexName): void;
}
