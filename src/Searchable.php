<?php

declare(strict_types=1);

namespace EzPhp\Search;

use ReflectionObject;
use ReflectionProperty;

/**
 * Trait Searchable
 *
 * Provides default implementations of searchableAs() and toSearchableArray()
 * for entities implementing SearchableInterface.
 *
 * The using class is still responsible for implementing getSearchableKey().
 *
 * Usage:
 *   class Article implements SearchableInterface
 *   {
 *       use Searchable;
 *
 *       public function __construct(
 *           public int    $id,
 *           public string $title,
 *           public string $body,
 *       ) {}
 *
 *       public function getSearchableKey(): string|int { return $this->id; }
 *   }
 *
 * @phpstan-require-implements SearchableInterface
 *
 * @package EzPhp\Search
 */
trait Searchable
{
    /**
     * Return the index name for this entity.
     *
     * Defaults to the lowercased short class name (e.g. "Article" → "article").
     * Override to use a different name.
     *
     * @return string
     */
    public function searchableAs(): string
    {
        return strtolower((new \ReflectionClass(static::class))->getShortName());
    }

    /**
     * Return the document data to index.
     *
     * Defaults to all public properties of the entity.
     * Override to control exactly which fields are indexed.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $result = [];

        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $result[$prop->getName()] = $prop->getValue($this);
        }

        return $result;
    }
}
