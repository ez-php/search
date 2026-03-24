<?php

declare(strict_types=1);

namespace Tests\Integration;

use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Events\ListenerInterface;
use EzPhp\Search\Drivers\NullDriver;
use EzPhp\Search\Events\DocumentIndexed;
use EzPhp\Search\Events\DocumentRemoved;
use EzPhp\Search\Listeners\HasSearchableModel;
use EzPhp\Search\Listeners\SyncSearchIndex;
use EzPhp\Search\SearchableInterface;
use EzPhp\Search\SearchIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Fixtures\Article;
use Tests\TestCase;

/**
 * Event fired when an article is created or updated.
 * Implements HasSearchableModel so SyncSearchIndex can index the entity.
 */
final class ArticleSavedEvent implements EventInterface, HasSearchableModel
{
    /**
     * @param Article $article
     */
    public function __construct(private readonly Article $article)
    {
    }

    /**
     * @return SearchableInterface
     */
    public function getSearchableModel(): SearchableInterface
    {
        return $this->article;
    }
}

/**
 * Event fired when an article is deleted.
 * Implements HasSearchableModel so SyncSearchIndex can remove the entity.
 */
final class ArticleDeletedEvent implements EventInterface, HasSearchableModel
{
    /**
     * @param Article $article
     */
    public function __construct(private readonly Article $article)
    {
    }

    /**
     * @return SearchableInterface
     */
    public function getSearchableModel(): SearchableInterface
    {
        return $this->article;
    }
}

/**
 * Unrelated domain event that does NOT carry a searchable model.
 * Used to verify that SyncSearchIndex silently ignores non-HasSearchableModel events.
 */
final class UserLoggedInEvent implements EventInterface
{
}

/**
 * Listener that collects all received events into a public array.
 * Reusable across test methods as a general-purpose event spy.
 */
final class CollectingListener implements ListenerInterface
{
    /** @var list<EventInterface> */
    public array $collected = [];

    /**
     * @param EventInterface $event
     *
     * @return void
     */
    public function handle(EventInterface $event): void
    {
        $this->collected[] = $event;
    }
}

/**
 * Integration tests for event-driven search index synchronisation.
 *
 * Tests exercise the full pipeline:
 *   ORM model event → EventDispatcher → SyncSearchIndex listener
 *   → SearchIndex → SearchDriverInterface (NullDriver)
 *
 * No external search engine is required — NullDriver stores documents in memory
 * and supports basic substring search for assertion purposes.
 *
 * Scenarios covered:
 *   - ArticleSaved event triggers indexing of the entity in the search driver
 *   - ArticleDeleted event triggers removal of the entity from the search driver
 *   - Non-HasSearchableModel events are silently ignored by SyncSearchIndex
 *   - Multiple entities are indexed via sequential events
 *   - DocumentIndexed event is fired as part of the sync chain (add path)
 *   - DocumentRemoved event is fired as part of the sync chain (remove path)
 *   - Indexed entity is findable via SearchIndex::search() after event dispatch
 *   - Removed entity is no longer returned by search after delete event
 *   - Entity re-indexed via event (same ID) replaces the existing document
 */
#[CoversClass(SyncSearchIndex::class)]
#[CoversClass(SearchIndex::class)]
#[UsesClass(NullDriver::class)]
#[UsesClass(DocumentIndexed::class)]
#[UsesClass(DocumentRemoved::class)]
final class EventDrivenIndexSyncTest extends TestCase
{
    private NullDriver $driver;

    private EventDispatcher $dispatcher;

    private SearchIndex $searchIndex;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->driver = new NullDriver();
        $this->dispatcher = new EventDispatcher();
        $this->searchIndex = new SearchIndex($this->driver, $this->dispatcher);
    }

    // ─── Index sync ───────────────────────────────────────────────────────────

    /**
     * Dispatching an ArticleSaved event triggers SyncSearchIndex which indexes
     * the entity in the search driver.
     *
     * @return void
     */
    public function testArticleSavedEventIndexesEntityInSearchDriver(): void
    {
        $this->dispatcher->listen(
            ArticleSavedEvent::class,
            new SyncSearchIndex($this->searchIndex),
        );

        $article = new Article(1, 'PHP 8.5 Features', 'Exciting new features...');
        $this->dispatcher->dispatch(new ArticleSavedEvent($article));

        $all = $this->driver->all();
        $this->assertArrayHasKey('article', $all);
        $this->assertArrayHasKey(1, $all['article']);
        $this->assertSame('PHP 8.5 Features', $all['article'][1]['title']);
    }

    /**
     * Dispatching an ArticleDeleted event triggers SyncSearchIndex (remove mode)
     * which removes the entity from the search driver.
     *
     * @return void
     */
    public function testArticleDeletedEventRemovesEntityFromSearchDriver(): void
    {
        $article = new Article(2, 'To Be Deleted', 'Content');
        $this->searchIndex->add($article);
        $this->assertArrayHasKey(2, $this->driver->all()['article'] ?? []);

        $this->dispatcher->listen(
            ArticleDeletedEvent::class,
            new SyncSearchIndex($this->searchIndex, remove: true),
        );

        $this->dispatcher->dispatch(new ArticleDeletedEvent($article));

        $this->assertArrayNotHasKey(2, $this->driver->all()['article'] ?? []);
    }

    /**
     * SyncSearchIndex silently ignores events that do not implement HasSearchableModel.
     *
     * @return void
     */
    public function testSyncListenerIgnoresNonHasSearchableModelEvents(): void
    {
        $this->dispatcher->listen(
            UserLoggedInEvent::class,
            new SyncSearchIndex($this->searchIndex),
        );

        $this->dispatcher->dispatch(new UserLoggedInEvent());

        $this->assertEmpty($this->driver->all(), 'No document should be indexed for an unrelated event');
    }

    /**
     * Sequential ArticleSaved events each index a distinct document.
     *
     * @return void
     */
    public function testMultipleEntitiesAreIndexedViaSequentialEvents(): void
    {
        $listener = new SyncSearchIndex($this->searchIndex);
        $this->dispatcher->listen(ArticleSavedEvent::class, $listener);

        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(1, 'First', 'Body 1')));
        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(2, 'Second', 'Body 2')));
        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(3, 'Third', 'Body 3')));

        $this->assertCount(3, $this->driver->all()['article'] ?? []);
    }

    // ─── Secondary DocumentIndexed / DocumentRemoved events ──────────────────

    /**
     * When SyncSearchIndex indexes an entity, SearchIndex fires DocumentIndexed.
     * This tests the full chain: model event → sync listener → search index → domain event.
     *
     * @return void
     */
    public function testDocumentIndexedEventFiredAsSideEffectOfSyncIndex(): void
    {
        $spy = new CollectingListener();
        $this->dispatcher->listen(DocumentIndexed::class, $spy);

        $this->dispatcher->listen(
            ArticleSavedEvent::class,
            new SyncSearchIndex($this->searchIndex),
        );

        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(5, 'Event Chain', 'Body')));

        $this->assertCount(1, $spy->collected);
        $this->assertInstanceOf(DocumentIndexed::class, $spy->collected[0]);

        /** @var DocumentIndexed $indexed */
        $indexed = $spy->collected[0];
        $this->assertSame('article', $indexed->index);
        $this->assertSame(5, $indexed->id);
    }

    /**
     * When SyncSearchIndex removes an entity, SearchIndex fires DocumentRemoved.
     *
     * @return void
     */
    public function testDocumentRemovedEventFiredAsSideEffectOfSyncRemove(): void
    {
        $spy = new CollectingListener();
        $this->dispatcher->listen(DocumentRemoved::class, $spy);

        $article = new Article(6, 'To Remove', 'Body');
        $this->searchIndex->add($article);

        $this->dispatcher->listen(
            ArticleDeletedEvent::class,
            new SyncSearchIndex($this->searchIndex, remove: true),
        );

        $this->dispatcher->dispatch(new ArticleDeletedEvent($article));

        $this->assertCount(1, $spy->collected);
        $this->assertInstanceOf(DocumentRemoved::class, $spy->collected[0]);

        /** @var DocumentRemoved $removed */
        $removed = $spy->collected[0];
        $this->assertSame('article', $removed->index);
        $this->assertSame(6, $removed->id);
    }

    // ─── End-to-end: index then search ───────────────────────────────────────

    /**
     * An entity indexed via an event dispatch is subsequently findable
     * through SearchIndex::search().
     *
     * @return void
     */
    public function testSearchableEntityAvailableAfterEventDrivenIndexing(): void
    {
        $this->dispatcher->listen(
            ArticleSavedEvent::class,
            new SyncSearchIndex($this->searchIndex),
        );

        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(7, 'Searchable PHP Article', 'PHP content')));
        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(8, 'Searchable Java Article', 'Java content')));

        $result = $this->searchIndex->search('article', 'PHP');

        $this->assertSame(1, $result->total);
        $this->assertSame(7, $result->hits[0]->id);
    }

    /**
     * An entity removed via a delete event is no longer returned by
     * SearchIndex::search().
     *
     * @return void
     */
    public function testRemovedEntityNotReturnedInSearchAfterDeleteEvent(): void
    {
        $this->dispatcher->listen(
            ArticleSavedEvent::class,
            new SyncSearchIndex($this->searchIndex),
        );
        $this->dispatcher->listen(
            ArticleDeletedEvent::class,
            new SyncSearchIndex($this->searchIndex, remove: true),
        );

        $article = new Article(9, 'Soon To Be Gone', 'Content');

        $this->dispatcher->dispatch(new ArticleSavedEvent($article));
        $this->assertSame(1, $this->searchIndex->search('article', 'Soon To Be Gone')->total);

        $this->dispatcher->dispatch(new ArticleDeletedEvent($article));
        $this->assertSame(0, $this->searchIndex->search('article', 'Soon To Be Gone')->total);
    }

    /**
     * Dispatching a second ArticleSaved event for the same entity ID replaces
     * the existing document — the index never grows beyond one entry for that ID.
     *
     * @return void
     */
    public function testEntityCanBeReindexedViaEventAfterModification(): void
    {
        $this->dispatcher->listen(
            ArticleSavedEvent::class,
            new SyncSearchIndex($this->searchIndex),
        );

        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(10, 'Old Title', 'Old body')));
        $this->assertSame('Old Title', $this->driver->all()['article'][10]['title']);

        $this->dispatcher->dispatch(new ArticleSavedEvent(new Article(10, 'New Title', 'New body')));
        $this->assertSame('New Title', $this->driver->all()['article'][10]['title']);
        $this->assertCount(1, $this->driver->all()['article']);
    }
}
