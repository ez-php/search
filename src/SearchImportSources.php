<?php

declare(strict_types=1);

namespace EzPhp\Search;

use Closure;

/**
 * Class SearchImportSources
 *
 * Named sources `search:import` reads documents from. The module knows nothing
 * about how an application stores its entities (ORM, plain PDO, an API), so the
 * application registers a closure per source that yields SearchableInterface
 * instances — typically in its own provider's boot():
 *
 *   $app->make(SearchImportSources::class)
 *       ->register('posts', fn (): iterable => $posts->findAll());
 *
 * A generator keeps memory flat for large tables. Bound as a singleton by
 * SearchServiceProvider.
 *
 * @package EzPhp\Search
 */
final class SearchImportSources
{
    /**
     * @var array<string, Closure(): iterable<SearchableInterface>>
     */
    private array $sources = [];

    /**
     * Register (or replace) a source.
     *
     * @param string                                 $name   Name passed to `search:import`, e.g. 'posts' or a class name.
     * @param Closure(): iterable<SearchableInterface> $source Yields the documents to index.
     *
     * @return self
     */
    public function register(string $name, Closure $source): self
    {
        $this->sources[$name] = $source;

        return $this;
    }

    /**
     * Whether a source with this name is registered.
     *
     * @param string $name
     *
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->sources[$name]);
    }

    /**
     * The documents of a source.
     *
     * @param string $name
     *
     * Typed as `iterable<mixed>` on purpose: the closure is application code, so
     * SearchImportCommand checks every value instead of trusting its declared type.
     *
     * @throws SearchException When no source with this name is registered.
     *
     * @return iterable<mixed>
     */
    public function documents(string $name): iterable
    {
        if (!isset($this->sources[$name])) {
            throw new SearchException(sprintf('No search import source named "%s" is registered.', $name));
        }

        return ($this->sources[$name])();
    }

    /**
     * Registered source names, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->sources);
    }
}
