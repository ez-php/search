<?php

declare(strict_types=1);

namespace EzPhp\Search\Listeners;

use EzPhp\Events\EventInterface;
use EzPhp\Events\ListenerInterface;
use EzPhp\Search\SearchIndex;

/**
 * Class SyncSearchIndex
 *
 * Event listener that keeps the search index in sync with model lifecycle events.
 *
 * Register this listener for your ORM model events that implement HasSearchableModel.
 * Use $remove = true to register a listener for delete events.
 *
 * Usage (in a service provider's boot()):
 *
 *   Event::listen(ArticleSaved::class, new SyncSearchIndex($searchIndex));
 *   Event::listen(ArticleDeleted::class, new SyncSearchIndex($searchIndex, remove: true));
 *
 * @package EzPhp\Search\Listeners
 */
final class SyncSearchIndex implements ListenerInterface
{
    /**
     * SyncSearchIndex Constructor
     *
     * @param SearchIndex $index   The search index to update.
     * @param bool        $remove  When true, removes the entity from the index instead of adding it.
     */
    public function __construct(
        private readonly SearchIndex $index,
        private readonly bool $remove = false,
    ) {
    }

    /**
     * Handle the incoming event.
     *
     * Silently ignored if the event does not implement HasSearchableModel.
     *
     * @param EventInterface $event
     *
     * @return void
     */
    public function handle(EventInterface $event): void
    {
        if (!$event instanceof HasSearchableModel) {
            return;
        }

        $model = $event->getSearchableModel();

        if ($this->remove) {
            $this->index->remove($model);
        } else {
            $this->index->add($model);
        }
    }
}
