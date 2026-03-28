<?php

declare(strict_types=1);

namespace EzPhp\Search\Drivers;

use EzPhp\Search\SearchDriverInterface;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;

/**
 * Class NullDriver
 *
 * In-memory search driver intended for testing and local development without
 * a running search engine. Documents are stored in a plain PHP array and
 * queried via simple case-insensitive substring matching.
 *
 * @package EzPhp\Search\Drivers
 */
final class NullDriver implements SearchDriverInterface
{
    /**
     * In-memory document store keyed by index name and document id.
     *
     * @var array<string, array<string|int, array<string, mixed>>>
     */
    private array $store = [];

    /**
     * @param string               $indexName
     * @param string|int           $id
     * @param array<string, mixed> $document
     *
     * @return void
     */
    public function index(string $indexName, string|int $id, array $document): void
    {
        $this->store[$indexName][$id] = $document;
    }

    /**
     * @param string     $indexName
     * @param string|int $id
     *
     * @return void
     */
    public function remove(string $indexName, string|int $id): void
    {
        unset($this->store[$indexName][$id]);
    }

    /**
     * @param string        $indexName
     * @param string        $query
     * @param SearchOptions $options
     *
     * @return SearchResult
     */
    public function search(string $indexName, string $query, SearchOptions $options): SearchResult
    {
        $documents = $this->store[$indexName] ?? [];

        $hits = [];

        foreach ($documents as $id => $doc) {
            if ($query === '' || $this->matches($doc, $query)) {
                $hits[] = new SearchHit($id, 1.0, $doc);
            }
        }

        $total = count($hits);
        $page = array_slice($hits, $options->offset, $options->limit > 0 ? $options->limit : null);

        return new SearchResult($page, $total, 0);
    }

    /**
     * @param string $indexName
     *
     * @return void
     */
    public function flush(string $indexName): void
    {
        unset($this->store[$indexName]);
    }

    /**
     * Return the full in-memory store (useful for assertions in tests).
     *
     * @return array<string, array<string|int, array<string, mixed>>>
     */
    public function all(): array
    {
        return $this->store;
    }

    /**
     * Return true if any scalar value in the document contains the query string.
     *
     * @param array<string, mixed> $document
     * @param string               $query
     *
     * @return bool
     */
    private function matches(array $document, string $query): bool
    {
        $lower = strtolower($query);

        foreach ($document as $value) {
            if (is_scalar($value) && str_contains(strtolower((string) $value), $lower)) {
                return true;
            }
        }

        return false;
    }
}
