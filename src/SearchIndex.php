<?php

declare(strict_types=1);

namespace EzPhp\Search;

use EzPhp\Events\EventDispatcher;
use EzPhp\Search\Events\DocumentIndexed;
use EzPhp\Search\Events\DocumentRemoved;

/**
 * Class SearchIndex
 *
 * Central entry point for all search operations.
 *
 * Delegates index/remove/search/flush calls to the configured driver and
 * optionally dispatches events after each mutation so that listeners can
 * react (e.g. to trigger cache invalidation or send audit logs).
 *
 * Usage:
 *   $index->add($article);                        // index a single entity
 *   $index->remove($article);                     // remove it from the index
 *   $result = $index->search('article', 'php');   // full-text search
 *   $index->flush('article');                     // clear an entire index
 *
 * @package EzPhp\Search
 */
final class SearchIndex
{
    /**
     * SearchIndex Constructor
     *
     * @param SearchDriverInterface $driver     The underlying search engine adapter.
     * @param EventDispatcher|null  $dispatcher Optional event dispatcher. When set, DocumentIndexed
     *                                          and DocumentRemoved events are fired after mutations.
     */
    public function __construct(
        private readonly SearchDriverInterface $driver,
        private readonly ?EventDispatcher $dispatcher = null,
    ) {
    }

    /**
     * Add or replace a searchable entity in the index.
     *
     * @param SearchableInterface $entity
     *
     * @return void
     */
    public function add(SearchableInterface $entity): void
    {
        $this->driver->index(
            $entity->searchableAs(),
            $entity->getSearchableKey(),
            $entity->toSearchableArray(),
        );

        $this->dispatcher?->dispatch(
            new DocumentIndexed($entity->searchableAs(), $entity->getSearchableKey()),
        );
    }

    /**
     * Remove a searchable entity from the index.
     *
     * @param SearchableInterface $entity
     *
     * @return void
     */
    public function remove(SearchableInterface $entity): void
    {
        $this->driver->remove(
            $entity->searchableAs(),
            $entity->getSearchableKey(),
        );

        $this->dispatcher?->dispatch(
            new DocumentRemoved($entity->searchableAs(), $entity->getSearchableKey()),
        );
    }

    /**
     * Execute a full-text search query.
     *
     * @param string             $index   The index name to search in.
     * @param string             $query   The search query string.
     * @param SearchOptions|null $options Pagination, filter, and sort options. Defaults to offset=0, limit=20.
     *
     * @return SearchResult
     */
    public function search(string $index, string $query, ?SearchOptions $options = null): SearchResult
    {
        return $this->driver->search($index, $query, $options ?? new SearchOptions());
    }

    /**
     * Remove all documents from the given index.
     *
     * @param string $index The index name to flush.
     *
     * @return void
     */
    public function flush(string $index): void
    {
        $this->driver->flush($index);
    }

    /**
     * Return the underlying driver instance.
     *
     * @return SearchDriverInterface
     */
    public function getDriver(): SearchDriverInterface
    {
        return $this->driver;
    }
}
