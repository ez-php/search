<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EzPhp\Search\Drivers\TypesenseDriver;
use EzPhp\Search\SearchOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;
use Throwable;

/**
 * Class TypesenseDriverTest
 *
 * Integration tests for the Typesense driver.
 * All tests are skipped when the configured Typesense instance is unreachable.
 *
 * @package Tests\Drivers
 */
#[CoversClass(TypesenseDriver::class)]
#[UsesClass(SearchOptions::class)]
#[Group('typesense')]
final class TypesenseDriverTest extends TestCase
{
    private const string HOST = 'http://typesense:8108';

    private const string API_KEY = 'test-api-key';

    private const string TEST_INDEX = 'test_articles';

    private TypesenseDriver $driver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('TYPESENSE_HOST');
        $key = getenv('TYPESENSE_API_KEY');

        $resolvedHost = is_string($host) && $host !== '' ? $host : self::HOST;
        $resolvedKey = is_string($key) && $key !== '' ? $key : self::API_KEY;

        try {
            $this->driver = new TypesenseDriver($resolvedHost, $resolvedKey);
            $this->driver->flush(self::TEST_INDEX);
        } catch (Throwable $e) {
            $this->markTestSkipped('Typesense not reachable: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        try {
            $this->driver->flush(self::TEST_INDEX);
        } catch (Throwable) {
            // ignore
        }
    }

    public function testIndexAndSearchDocument(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-1', ['title' => 'Hello World', 'body' => 'Test content']);

        $result = $this->driver->search(self::TEST_INDEX, 'Hello', new SearchOptions());

        $this->assertGreaterThanOrEqual(1, $result->total);
        $this->assertNotEmpty($result->hits);
        $this->assertSame('doc-1', $result->hits[0]->id);
    }

    public function testIndexOverwritesExistingDocument(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-1', ['title' => 'Original']);
        $this->driver->index(self::TEST_INDEX, 'doc-1', ['title' => 'Updated']);

        $result = $this->driver->search(self::TEST_INDEX, 'Updated', new SearchOptions());

        $this->assertGreaterThanOrEqual(1, $result->total);
    }

    public function testRemoveDocument(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-2', ['title' => 'ToDelete']);
        $this->driver->remove(self::TEST_INDEX, 'doc-2');

        $result = $this->driver->search(self::TEST_INDEX, 'ToDelete', new SearchOptions());

        $this->assertSame(0, $result->total);
    }

    public function testRemoveNonExistentDocumentDoesNotThrow(): void
    {
        $this->driver->remove(self::TEST_INDEX, 'non-existent-id');

        $this->addToAssertionCount(1);
    }

    public function testFlushEmptiesIndex(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-3', ['title' => 'Before flush']);
        $this->driver->flush(self::TEST_INDEX);

        // After flush, the collection is deleted; indexing recreates it.
        $this->driver->index(self::TEST_INDEX, 'doc-4', ['title' => 'After flush']);

        $result = $this->driver->search(self::TEST_INDEX, 'Before flush', new SearchOptions());

        $this->assertSame(0, $result->total);
    }

    public function testFlushNonExistentCollectionDoesNotThrow(): void
    {
        $this->driver->flush('nonexistent_collection_xyz');

        $this->addToAssertionCount(1);
    }

    public function testSearchReturnsEmptyResultForMiss(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-5', ['title' => 'Something unrelated']);

        $result = $this->driver->search(self::TEST_INDEX, 'zzznomatch', new SearchOptions());

        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->hits);
    }

    public function testSearchWithPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->index(self::TEST_INDEX, "page-doc-{$i}", ['title' => "Article number {$i}"]);
        }

        $options = new SearchOptions(offset: 0, limit: 2);
        $result = $this->driver->search(self::TEST_INDEX, 'Article', $options);

        $this->assertLessThanOrEqual(2, count($result->hits));
        $this->assertGreaterThanOrEqual(2, $result->total);
    }
}
