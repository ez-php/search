<?php

declare(strict_types=1);

namespace Tests\Search;

use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Events\ListenerInterface;
use EzPhp\Search\Drivers\NullDriver;
use EzPhp\Search\Events\DocumentIndexed;
use EzPhp\Search\Events\DocumentRemoved;
use EzPhp\Search\SearchIndex;
use EzPhp\Search\SearchOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Fixtures\Article;
use Tests\TestCase;

/**
 * Class SearchIndexTest
 *
 * @package Tests\Search
 */
#[CoversClass(SearchIndex::class)]
#[UsesClass(NullDriver::class)]
#[UsesClass(DocumentIndexed::class)]
#[UsesClass(DocumentRemoved::class)]
#[UsesClass(SearchOptions::class)]
final class SearchIndexTest extends TestCase
{
    private NullDriver $driver;

    private SearchIndex $index;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new NullDriver();
        $this->index = new SearchIndex($this->driver);
    }

    // ─── add ──────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_add_stores_document_in_driver(): void
    {
        $article = new Article(1, 'Hello World', 'Content here.');

        $this->index->add($article);

        $all = $this->driver->all();
        $this->assertArrayHasKey('article', $all);
        $this->assertArrayHasKey(1, $all['article']);
    }

    /**
     * @return void
     */
    public function test_add_passes_correct_document_data(): void
    {
        $article = new Article(5, 'PHP Tips', 'Great tips.');

        $this->index->add($article);

        $doc = $this->driver->all()['article'][5];
        $this->assertSame(5, $doc['id']);
        $this->assertSame('PHP Tips', $doc['title']);
        $this->assertSame('Great tips.', $doc['body']);
    }

    // ─── remove ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_remove_deletes_document_from_driver(): void
    {
        $article = new Article(2, 'To Remove', 'Body.');
        $this->index->add($article);
        $this->index->remove($article);

        $this->assertArrayNotHasKey(2, $this->driver->all()['article'] ?? []);
    }

    // ─── search ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_search_delegates_to_driver(): void
    {
        $this->index->add(new Article(1, 'PHP', 'Great language.'));
        $this->index->add(new Article(2, 'JavaScript', 'Also great.'));

        $result = $this->index->search('article', 'PHP');

        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->hits[0]->id);
    }

    /**
     * @return void
     */
    public function test_search_uses_default_options_when_none_given(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->index->add(new Article($i, "Article {$i}", 'content'));
        }

        $result = $this->index->search('article', '');

        $this->assertSame(5, $result->total);
    }

    /**
     * @return void
     */
    public function test_search_accepts_explicit_options(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->index->add(new Article($i, "Article {$i}", 'content'));
        }

        $result = $this->index->search('article', '', (new SearchOptions())->withLimit(5)->withOffset(10));

        $this->assertSame(30, $result->total);
        $this->assertCount(5, $result->hits);
    }

    // ─── flush ────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_flush_clears_entire_index(): void
    {
        $this->index->add(new Article(1, 'One', 'Body'));
        $this->index->add(new Article(2, 'Two', 'Body'));
        $this->index->flush('article');

        $this->assertEmpty($this->driver->all()['article'] ?? []);
    }

    // ─── get driver ───────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_driver_returns_injected_driver(): void
    {
        $this->assertSame($this->driver, $this->index->getDriver());
    }

    // ─── events ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_add_dispatches_document_indexed_event(): void
    {
        $dispatcher = new EventDispatcher();
        $index = new SearchIndex($this->driver, $dispatcher);

        $listener = new class () implements ListenerInterface {
            public ?EventInterface $received = null;

            /**
             * @param EventInterface $event
             *
             * @return void
             */
            public function handle(EventInterface $event): void
            {
                $this->received = $event;
            }
        };

        $dispatcher->listen(DocumentIndexed::class, $listener);

        $index->add(new Article(3, 'Event Test', 'Body'));

        $this->assertInstanceOf(DocumentIndexed::class, $listener->received);
        $this->assertSame('article', $listener->received->index);
        $this->assertSame(3, $listener->received->id);
    }

    /**
     * @return void
     */
    public function test_remove_dispatches_document_removed_event(): void
    {
        $dispatcher = new EventDispatcher();
        $index = new SearchIndex($this->driver, $dispatcher);
        $article = new Article(4, 'To Remove', 'Body');
        $index->add($article);

        $listener = new class () implements ListenerInterface {
            public ?EventInterface $received = null;

            /**
             * @param EventInterface $event
             *
             * @return void
             */
            public function handle(EventInterface $event): void
            {
                $this->received = $event;
            }
        };

        $dispatcher->listen(DocumentRemoved::class, $listener);

        $index->remove($article);

        $this->assertInstanceOf(DocumentRemoved::class, $listener->received);
        $this->assertSame('article', $listener->received->index);
        $this->assertSame(4, $listener->received->id);
    }

    /**
     * @return void
     */
    public function test_no_events_fired_without_dispatcher(): void
    {
        // Should complete without error — no dispatcher means no event dispatch.
        $article = new Article(5, 'No Events', 'Body');
        $this->index->add($article);
        $this->index->remove($article);

        $this->addToAssertionCount(1);
    }
}
