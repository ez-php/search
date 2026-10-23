<?php

declare(strict_types=1);

namespace EzPhp\Search\Console;

use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;
use EzPhp\Console\Output;
use EzPhp\Search\SearchIndex;

/**
 * Class SearchFlushCommand
 *
 * `search:flush <index>` — removes every document from one index through the
 * configured driver (SearchIndex::flush()).
 *
 * @package EzPhp\Search\Console
 */
final readonly class SearchFlushCommand implements CommandInterface
{
    /**
     * @param SearchIndex   $index
     * @param resource|null $errorStream Where errors go; null = STDERR (injectable for tests).
     */
    public function __construct(
        private SearchIndex $index,
        private mixed $errorStream = null,
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'search:flush';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Remove every document from a search index';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return 'Usage: ez search:flush <index>   (the index name, i.e. SearchableInterface::searchableAs())';
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $name = (new Input($args))->argument(0);

        if ($name === null || $name === '') {
            Output::error('Missing index: ez search:flush <index>.', $this->errorStream);

            return 1;
        }

        $this->index->flush($name);
        Output::success(sprintf('Flushed search index "%s".', $name));

        return 0;
    }
}
