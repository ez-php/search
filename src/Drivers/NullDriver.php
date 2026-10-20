<?php

declare(strict_types=1);

namespace EzPhp\Search\Drivers;

use EzPhp\Search\SearchDriverInterface;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;

/**
 * Class NullDriver
 *
 * In-memory search driver intended for testing and local development without
 * a running search engine. Documents are stored in a plain PHP array.
 *
 * search() is deliberately naive — no relevance ranking (every hit scores 1.0):
 * - every whitespace-separated query term must occur (case-insensitive
 *   substring) in some scalar value of the document; '' matches all;
 * - SearchOptions::$filter supports `field = value` / `field != value`
 *   conditions joined by AND (values bare or quoted); anything else throws a
 *   SearchException instead of being silently ignored;
 * - SearchOptions::$sort descriptors (`field:asc|desc`) order the hits before
 *   offset/limit are applied.
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
        $terms = preg_split('/\s+/', strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $conditions = self::parseFilter($options->filter);

        $hits = [];

        foreach ($documents as $id => $doc) {
            if ($this->matches($doc, $terms) && self::passesFilter($doc, $conditions)) {
                $hits[] = new SearchHit($id, 1.0, $doc);
            }
        }

        $hits = self::sort($hits, $options->sort);
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
     * Return true if every term occurs in some scalar value of the document.
     *
     * @param array<string, mixed> $document
     * @param list<string>         $terms    Lower-cased query terms; empty matches everything.
     *
     * @return bool
     */
    private function matches(array $document, array $terms): bool
    {
        foreach ($terms as $term) {
            $found = false;

            foreach ($document as $value) {
                if (is_scalar($value) && str_contains(strtolower((string) $value), $term)) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse `field = value [AND field != value ...]` into conditions.
     *
     * @param string $filter
     *
     * @return list<array{field: string, negate: bool, value: string}>
     * @throws SearchException On any other syntax.
     */
    private static function parseFilter(string $filter): array
    {
        if (trim($filter) === '') {
            return [];
        }

        $conditions = [];

        foreach (preg_split('/\s+AND\s+/i', trim($filter)) ?: [] as $part) {
            if (preg_match('/^([A-Za-z0-9_.]+)\s*(!=|=)\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))$/', trim($part), $m) !== 1) {
                throw new SearchException(
                    "NullDriver only supports `field = value` / `field != value` filters joined by AND, got: {$filter}"
                );
            }

            $conditions[] = [
                'field' => $m[1],
                'negate' => $m[2] === '!=',
                'value' => ($m[3] ?? '') . ($m[4] ?? '') . ($m[5] ?? ''),
            ];
        }

        return $conditions;
    }

    /**
     * @param array<string, mixed>                                    $document
     * @param list<array{field: string, negate: bool, value: string}> $conditions
     *
     * @return bool
     */
    private static function passesFilter(array $document, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $equal = self::stringValue($document[$condition['field']] ?? null) === $condition['value'];

            if ($equal === $condition['negate']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stable sort by `field:asc|desc` descriptors (numeric-aware comparison).
     *
     * @param list<SearchHit> $hits
     * @param list<string>    $sort
     *
     * @return list<SearchHit>
     */
    private static function sort(array $hits, array $sort): array
    {
        if ($sort === []) {
            return $hits;
        }

        usort($hits, static function (SearchHit $a, SearchHit $b) use ($sort): int {
            foreach ($sort as $descriptor) {
                [$field, $direction] = array_pad(explode(':', $descriptor, 2), 2, 'asc');
                $left = $a->document[$field] ?? null;
                $right = $b->document[$field] ?? null;
                $cmp = is_numeric($left) && is_numeric($right)
                    ? $left <=> $right
                    : strcmp(self::stringValue($left), self::stringValue($right));

                if ($cmp !== 0) {
                    return strtolower($direction) === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        return $hits;
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function stringValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
