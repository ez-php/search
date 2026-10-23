<?php

declare(strict_types=1);

namespace EzPhp\Search\Console;

use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;
use EzPhp\Console\Output;
use EzPhp\Search\SearchableInterface;
use EzPhp\Search\SearchImportSources;
use EzPhp\Search\SearchIndex;

/**
 * Class SearchImportCommand
 *
 * `search:import <source>` — (re)indexes every document a registered
 * SearchImportSources source yields, through SearchIndex::add() (so the
 * DocumentIndexed event fires per document, as on a regular save). Use after a
 * mapping change or on a fresh deploy; run `search:flush <index>` first to drop
 * documents that no longer exist.
 *
 * @package EzPhp\Search\Console
 */
final readonly class SearchImportCommand implements CommandInterface
{
    /**
     * @param SearchIndex         $index
     * @param SearchImportSources $sources
     * @param resource|null       $errorStream Where errors go; null = STDERR (injectable for tests).
     */
    public function __construct(
        private SearchIndex $index,
        private SearchImportSources $sources,
        private mixed $errorStream = null,
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'search:import';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Index every document of a registered import source';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        $names = $this->sources->names();

        return implode("\n", [
            'Usage: ez search:import <source>',
            '',
            'Sources are registered with SearchImportSources::register(); registered: '
            . ($names === [] ? '(none)' : implode(', ', $names)),
        ]);
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
            Output::error('Missing source: ez search:import <source>.', $this->errorStream);

            return 1;
        }

        if (!$this->sources->has($name)) {
            $known = $this->sources->names();
            Output::error(sprintf(
                'Unknown import source "%s"; registered: %s.',
                $name,
                $known === [] ? '(none)' : implode(', ', $known),
            ), $this->errorStream);

            return 1;
        }

        $count = 0;

        foreach ($this->sources->documents($name) as $document) {
            if (!$document instanceof SearchableInterface) {
                Output::error(sprintf('Source "%s" yielded a %s, not a SearchableInterface.', $name, get_debug_type($document)), $this->errorStream);

                return 1;
            }

            $this->index->add($document);
            $count++;
        }

        Output::success(sprintf('Imported %d document(s) from "%s".', $count, $name));

        return 0;
    }
}
