<?php

declare(strict_types=1);

namespace Tests\Search;

use EzPhp\Search\Searchable;
use EzPhp\Search\SearchableInterface;
use Tests\Fixtures\Article;
use Tests\TestCase;

/**
 * Class SearchableTest
 *
 * Tests the Searchable trait via the Article fixture. Coverage for the trait
 * is captured indirectly through the Article class.
 *
 * @package Tests\Search
 */
final class SearchableTest extends TestCase
{
    /**
     * @return void
     */
    public function test_searchable_as_returns_lowercased_class_name(): void
    {
        $article = new Article(1, 'Hello', 'World');

        $this->assertSame('article', $article->searchableAs());
    }

    /**
     * @return void
     */
    public function test_to_searchable_array_returns_public_properties(): void
    {
        $article = new Article(42, 'PHP is great', 'It really is.');

        $data = $article->toSearchableArray();

        $this->assertSame(42, $data['id']);
        $this->assertSame('PHP is great', $data['title']);
        $this->assertSame('It really is.', $data['body']);
    }

    /**
     * @return void
     */
    public function test_get_searchable_key_returns_id(): void
    {
        $article = new Article(7, 'Test', 'Content');

        $this->assertSame(7, $article->getSearchableKey());
    }

    /**
     * @return void
     */
    public function test_searchable_as_can_be_overridden(): void
    {
        $entity = new class () implements SearchableInterface {
            use Searchable;

            public function searchableAs(): string
            {
                return 'custom_index';
            }

            public function getSearchableKey(): string
            {
                return 'key-1';
            }
        };

        $this->assertSame('custom_index', $entity->searchableAs());
    }

    /**
     * @return void
     */
    public function test_to_searchable_array_can_be_overridden(): void
    {
        $entity = new class () implements SearchableInterface {
            use Searchable;

            public int $id = 1;

            public string $secret = 'hidden';

            public function getSearchableKey(): int
            {
                return $this->id;
            }

            /**
             * @return array<string, mixed>
             */
            public function toSearchableArray(): array
            {
                return ['id' => $this->id];
            }
        };

        $this->assertSame(['id' => 1], $entity->toSearchableArray());
    }
}
