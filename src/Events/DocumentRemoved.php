<?php

declare(strict_types=1);

namespace EzPhp\Search\Events;

use EzPhp\Events\EventInterface;

/**
 * Class DocumentRemoved
 *
 * Fired by SearchIndex after a document has been successfully removed from
 * the search engine.
 *
 * @package EzPhp\Search\Events
 */
final readonly class DocumentRemoved implements EventInterface
{
    /**
     * DocumentRemoved Constructor
     *
     * @param string     $index The index the document was removed from.
     * @param string|int $id    The document identifier.
     */
    public function __construct(
        public string $index,
        public string|int $id,
    ) {
    }
}
