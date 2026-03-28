<?php

declare(strict_types=1);

namespace EzPhp\Search\Events;

use EzPhp\Events\EventInterface;

/**
 * Class DocumentIndexed
 *
 * Fired by SearchIndex after a document has been successfully added or
 * replaced in the search engine.
 *
 * @package EzPhp\Search\Events
 */
final readonly class DocumentIndexed implements EventInterface
{
    /**
     * DocumentIndexed Constructor
     *
     * @param string     $index The index the document was written to.
     * @param string|int $id    The document identifier.
     */
    public function __construct(
        public string $index,
        public string|int $id,
    ) {
    }
}
