<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EzPhp\Database\Database;
use EzPhp\Search\Drivers\DatabaseDriver;
use EzPhp\Search\Drivers\DocumentFilter;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * DatabaseDriver on SQLite FTS5 (in memory): term matching, relevance order,
 * replace-on-index, filters/sort/paging, index isolation, flush.
 */
#[CoversClass(DatabaseDriver::class)]
#[CoversClass(DocumentFilter::class)]
#[UsesClass(SearchHit::class)]
#[UsesClass(SearchOptions::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(SearchException::class)]
final class SearchDatabaseDriverTest extends TestCase
{
    private DatabaseDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new DatabaseDriver(new Database('sqlite::memory:', '', ''));
        $this->driver->index('articles', 1, ['title' => 'PHP performance tips', 'body' => 'opcache and preloading', 'status' => 'published', 'views' => 10]);
        $this->driver->index('articles', 2, ['title' => 'Deploying PHP', 'body' => 'containers, PHP-FPM and php tuning', 'status' => 'draft', 'views' => 30]);
        $this->driver->index('articles', 'about', ['title' => 'About us', 'body' => 'a small team', 'status' => 'published', 'views' => 20]);
        $this->driver->index('pages', 1, ['title' => 'PHP pages index', 'status' => 'published']);
    }

    /**
     * @return list<int|string>
     */
    private function ids(SearchResult $result): array
    {
        return array_map(static fn (SearchHit $hit): int|string => $hit->id, $result->hits);
    }

    public function test_every_term_must_match_as_a_prefix(): void
    {
        $this->assertSame([1], $this->ids($this->driver->search('articles', 'perf PHP', new SearchOptions())));
        $this->assertSame([], $this->ids($this->driver->search('articles', 'php kubernetes', new SearchOptions())));
    }

    public function test_hits_are_ordered_by_relevance_and_ids_keep_their_type(): void
    {
        $result = $this->driver->search('articles', 'php', new SearchOptions());

        $this->assertSame([2, 1], $this->ids($result)); // "php" appears three times in 2
        $this->assertSame(2, $result->total);
        $this->assertGreaterThan($result->hits[1]->score, $result->hits[0]->score);
        $this->assertSame(['about'], $this->ids($this->driver->search('articles', 'team', new SearchOptions())));
    }

    public function test_operators_in_the_query_are_neutralised(): void
    {
        $this->assertSame([1], $this->ids($this->driver->search('articles', '"opcache" +preload* (-)', new SearchOptions())));
    }

    public function test_empty_query_returns_the_whole_index_with_filters_sort_and_paging(): void
    {
        $options = new SearchOptions(offset: 1, limit: 1, filter: 'status = published', sort: ['views:desc']);
        $result = $this->driver->search('articles', '', $options);

        $this->assertSame(2, $result->total);
        $this->assertSame([1], $this->ids($result)); // published by views desc: about(20), 1(10)
    }

    public function test_indexing_an_existing_id_replaces_the_document(): void
    {
        $this->driver->index('articles', 1, ['title' => 'Rewritten', 'status' => 'published']);

        $this->assertSame([], $this->ids($this->driver->search('articles', 'opcache', new SearchOptions())));
        $this->assertSame([1], $this->ids($this->driver->search('articles', 'rewritten', new SearchOptions())));
    }

    public function test_remove_and_flush_are_scoped_to_one_index(): void
    {
        $this->driver->remove('articles', 2);
        $this->assertSame([1], $this->ids($this->driver->search('articles', 'php', new SearchOptions())));

        $this->driver->flush('articles');
        $this->assertSame(0, $this->driver->search('articles', '', new SearchOptions())->total);
        $this->assertSame([1], $this->ids($this->driver->search('pages', 'php', new SearchOptions())));
    }

    public function test_unsupported_filters_and_table_names_are_rejected(): void
    {
        try {
            $this->driver->search('articles', '', new SearchOptions(filter: 'views > 10'));
            $this->fail('Expected SearchException.');
        } catch (SearchException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(SearchException::class);
        new DatabaseDriver(new Database('sqlite::memory:', '', ''), 'bad-name');
    }
}
