<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EzPhp\Search\Drivers\NullDriver;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class NullDriverTest
 *
 * @package Tests\Drivers
 */
#[CoversClass(NullDriver::class)]
#[UsesClass(SearchOptions::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(SearchHit::class)]
final class NullDriverTest extends TestCase
{
    private NullDriver $driver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new NullDriver();
    }

    // ─── index ────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_index_stores_document(): void
    {
        $this->driver->index('articles', 1, ['title' => 'Hello']);

        $this->assertSame(['title' => 'Hello'], $this->driver->all()['articles'][1]);
    }

    /**
     * @return void
     */
    public function test_index_replaces_existing_document(): void
    {
        $this->driver->index('articles', 1, ['title' => 'Old']);
        $this->driver->index('articles', 1, ['title' => 'New']);

        $this->assertSame(['title' => 'New'], $this->driver->all()['articles'][1]);
    }

    /**
     * @return void
     */
    public function test_index_stores_string_id(): void
    {
        $this->driver->index('products', 'sku-42', ['name' => 'Widget']);

        $this->assertArrayHasKey('sku-42', $this->driver->all()['products']);
    }

    // ─── remove ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_remove_deletes_document(): void
    {
        $this->driver->index('articles', 1, ['title' => 'Hello']);
        $this->driver->remove('articles', 1);

        $this->assertArrayNotHasKey(1, $this->driver->all()['articles'] ?? []);
    }

    /**
     * @return void
     */
    public function test_remove_non_existent_document_does_not_throw(): void
    {
        $this->driver->remove('articles', 999);
        $this->addToAssertionCount(1);
    }

    // ─── search ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_search_empty_query_returns_all_documents(): void
    {
        $this->driver->index('articles', 1, ['title' => 'PHP']);
        $this->driver->index('articles', 2, ['title' => 'JavaScript']);

        $result = $this->driver->search('articles', '', new SearchOptions());

        $this->assertSame(2, $result->total);
    }

    /**
     * @return void
     */
    public function test_search_filters_by_query(): void
    {
        $this->driver->index('articles', 1, ['title' => 'PHP Programming']);
        $this->driver->index('articles', 2, ['title' => 'JavaScript Basics']);
        $this->driver->index('articles', 3, ['body' => 'Learn PHP today']);

        $result = $this->driver->search('articles', 'PHP', new SearchOptions());

        $this->assertSame(2, $result->total);
    }

    /**
     * @return void
     */
    public function test_search_is_case_insensitive(): void
    {
        $this->driver->index('articles', 1, ['title' => 'PHP Programming']);

        $result = $this->driver->search('articles', 'php programming', new SearchOptions());

        $this->assertSame(1, $result->total);
    }

    /**
     * @return void
     */
    public function test_search_returns_empty_result_for_unknown_index(): void
    {
        $result = $this->driver->search('nonexistent', 'query', new SearchOptions());

        $this->assertSame(0, $result->total);
        $this->assertEmpty($result->hits);
    }

    /**
     * @return void
     */
    public function test_search_returns_zero_took(): void
    {
        $result = $this->driver->search('articles', '', new SearchOptions());

        $this->assertSame(0, $result->took);
    }

    /**
     * @return void
     */
    public function test_search_paginates_with_offset_and_limit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->driver->index('articles', $i, ['title' => "Article {$i}"]);
        }

        $result = $this->driver->search('articles', '', (new SearchOptions())->withOffset(3)->withLimit(4));

        $this->assertSame(10, $result->total);
        $this->assertCount(4, $result->hits);
    }

    /**
     * @return void
     */
    public function test_search_offset_beyond_total_returns_empty_hits(): void
    {
        $this->driver->index('articles', 1, ['title' => 'Only one']);

        $result = $this->driver->search('articles', '', (new SearchOptions())->withOffset(10));

        $this->assertSame(1, $result->total);
        $this->assertEmpty($result->hits);
    }

    // ─── flush ────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_flush_removes_all_documents(): void
    {
        $this->driver->index('articles', 1, ['title' => 'One']);
        $this->driver->index('articles', 2, ['title' => 'Two']);
        $this->driver->flush('articles');

        $this->assertArrayNotHasKey('articles', $this->driver->all());
    }

    /**
     * @return void
     */
    public function test_flush_does_not_affect_other_indices(): void
    {
        $this->driver->index('articles', 1, ['title' => 'Article']);
        $this->driver->index('products', 1, ['name' => 'Widget']);
        $this->driver->flush('articles');

        $this->assertArrayHasKey('products', $this->driver->all());
    }
}
