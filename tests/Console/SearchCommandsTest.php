<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Search\Console\SearchFlushCommand;
use EzPhp\Search\Console\SearchImportCommand;
use EzPhp\Search\Drivers\NullDriver;
use EzPhp\Search\Events\DocumentIndexed;
use EzPhp\Search\SearchableInterface;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchImportSources;
use EzPhp\Search\SearchIndex;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Fixtures\Article;
use Tests\TestCase;

/**
 * search:import / search:flush over a NullDriver, with SearchImportSources.
 *
 * @package Tests\Console
 */
#[CoversClass(SearchImportCommand::class)]
#[CoversClass(SearchFlushCommand::class)]
#[CoversClass(SearchImportSources::class)]
#[UsesClass(SearchIndex::class)]
#[UsesClass(NullDriver::class)]
#[UsesClass(SearchOptions::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(DocumentIndexed::class)]
#[UsesClass(SearchException::class)]
final class SearchCommandsTest extends TestCase
{
    private NullDriver $driver;

    private SearchIndex $index;

    private SearchImportSources $sources;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new NullDriver();
        $this->index = new SearchIndex($this->driver);
        $this->sources = new SearchImportSources();
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);
        $this->errors = $errors;
    }

    /**
     * @return array{int, string, string}
     */
    private function runCommand(SearchImportCommand|SearchFlushCommand $command, string ...$args): array
    {
        ob_start();
        $code = $command->handle(array_values($args));
        $output = (string) ob_get_clean();
        rewind($this->errors);

        return [$code, $output, (string) stream_get_contents($this->errors)];
    }

    public function test_import_indexes_every_document_of_the_source(): void
    {
        $this->sources->register('articles', static function (): iterable {
            yield new Article(1, 'First', 'one');
            yield new Article(2, 'Second', 'two');
        });

        [$code, $output] = $this->runCommand(new SearchImportCommand($this->index, $this->sources, $this->errors), 'articles');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Imported 2 document(s) from "articles".', $output);
        $this->assertSame(2, $this->index->search('article', '')->total);
    }

    public function test_import_fires_document_indexed_per_document(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->listen(DocumentIndexed::class, static function (EventInterface $event) use (&$seen): void {
            if ($event instanceof DocumentIndexed) {
                $seen[] = $event->id;
            }
        });
        $this->sources->register('articles', static fn (): array => [new Article(7, 'T', 'B')]);

        $this->runCommand(new SearchImportCommand(new SearchIndex($this->driver, $dispatcher), $this->sources, $this->errors), 'articles');

        $this->assertSame([7], $seen);
    }

    public function test_import_rejects_missing_and_unknown_sources(): void
    {
        $this->sources->register('articles', static fn (): array => []);
        $command = new SearchImportCommand($this->index, $this->sources, $this->errors);

        [$code, , $errors] = $this->runCommand($command);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Missing source', $errors);

        [$code, , $errors] = $this->runCommand($command, 'posts');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown import source "posts"; registered: articles.', $errors);
        $this->assertStringContainsString('articles', $command->getHelp());
    }

    public function test_import_rejects_a_source_yielding_something_else(): void
    {
        // A source whose declared type lies — what a misconfigured application closure looks like at runtime.
        /** @var list<SearchableInterface> $bogus */
        $bogus = json_decode('["not a document"]', true);
        $this->sources->register('broken', static fn (): array => $bogus);

        [$code, , $errors] = $this->runCommand(new SearchImportCommand($this->index, $this->sources, $this->errors), 'broken');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('yielded a string', $errors);
    }

    public function test_flush_empties_the_named_index(): void
    {
        $this->index->add(new Article(1, 'First', 'one'));

        [$code, $output] = $this->runCommand(new SearchFlushCommand($this->index, $this->errors), 'article');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Flushed search index "article".', $output);
        $this->assertSame(0, $this->index->search('article', '')->total);
    }

    public function test_flush_needs_an_index_name(): void
    {
        $command = new SearchFlushCommand($this->index, $this->errors);

        [$code, , $errors] = $this->runCommand($command);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Missing index', $errors);
        $this->assertSame('search:flush', $command->getName());
        $this->assertSame('search:import', (new SearchImportCommand($this->index, $this->sources))->getName());
    }

    public function test_sources_registry(): void
    {
        $this->sources->register('a', static fn (): array => [])->register('b', static fn (): array => []);

        $this->assertSame(['a', 'b'], $this->sources->names());
        $this->assertTrue($this->sources->has('a'));

        $this->expectException(SearchException::class);
        $this->sources->documents('missing');
    }
}
