<?php

declare(strict_types=1);

namespace EzPhp\Search;

/**
 * Class SearchHit
 *
 * Immutable value object representing a single document returned by a search
 * query. Carries the document identifier, relevance score, and raw document
 * data as returned by the search engine.
 *
 * @package EzPhp\Search
 */
final readonly class SearchHit
{
    /**
     * SearchHit Constructor
     *
     * @param string|int           $id       The document identifier.
     * @param float                $score    Relevance score assigned by the search engine.
     * @param array<string, mixed> $document The indexed document data.
     */
    public function __construct(
        public string|int $id,
        public float $score,
        public array $document,
    ) {
    }
}
