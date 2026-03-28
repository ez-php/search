<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use EzPhp\Search\Searchable;
use EzPhp\Search\SearchableInterface;

/**
 * Class Article
 *
 * Test fixture entity that implements SearchableInterface via the Searchable trait.
 *
 * @package Tests\Fixtures
 */
final class Article implements SearchableInterface
{
    use Searchable;

    /**
     * Article Constructor
     *
     * @param int    $id
     * @param string $title
     * @param string $body
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
    ) {
    }

    /**
     * @return int
     */
    public function getSearchableKey(): int
    {
        return $this->id;
    }
}
