<?php

declare(strict_types=1);

namespace EzPhp\Search\Listeners;

use EzPhp\Search\SearchableInterface;

/**
 * Interface HasSearchableModel
 *
 * Marker interface for events that carry a searchable entity.
 *
 * Implement this on your ORM model events so that SyncSearchIndex can
 * automatically index or remove the entity without coupling to specific
 * ORM event classes.
 *
 * Example:
 *   class ArticleSaved implements EventInterface, HasSearchableModel
 *   {
 *       public function __construct(private Article $article) {}
 *       public function getSearchableModel(): SearchableInterface { return $this->article; }
 *   }
 *
 * Then in a service provider:
 *   Event::listen(ArticleSaved::class, new SyncSearchIndex($searchIndex));
 *   Event::listen(ArticleDeleted::class, new SyncSearchIndex($searchIndex, remove: true));
 *
 * @package EzPhp\Search\Listeners
 */
interface HasSearchableModel
{
    /**
     * Return the entity to index or remove.
     *
     * @return SearchableInterface
     */
    public function getSearchableModel(): SearchableInterface;
}
