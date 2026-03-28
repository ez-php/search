<?php

declare(strict_types=1);

namespace EzPhp\Search;

/**
 * Interface SearchableInterface
 *
 * Contract for entities that can be indexed and searched.
 *
 * Implement this interface (and optionally use the Searchable trait for
 * default implementations of searchableAs() and toSearchableArray()) on any
 * entity you want to make discoverable via full-text search.
 *
 * Example:
 *   class Article implements SearchableInterface
 *   {
 *       use Searchable;
 *
 *       public function getSearchableKey(): string|int { return $this->id; }
 *   }
 *
 * @package EzPhp\Search
 */
interface SearchableInterface
{
    /**
     * Return the name of the search index this entity belongs to.
     *
     * Defaults (via Searchable trait) to the lowercased short class name.
     *
     * @return string
     */
    public function searchableAs(): string;

    /**
     * Return the unique identifier for this document in the search index.
     *
     * @return string|int
     */
    public function getSearchableKey(): string|int;

    /**
     * Return the document data to be indexed.
     *
     * Defaults (via Searchable trait) to all public properties.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array;
}
