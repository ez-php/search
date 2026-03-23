<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EzPhp\Search\Drivers\MeilisearchDriver;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;
use Throwable;

/**
 * Class MeilisearchDriverTest
 *
 * Integration tests for the Meilisearch driver.
 * All tests are skipped when the configured Meilisearch instance is unreachable.
 *
 * @package Tests\Drivers
 */
#[CoversClass(MeilisearchDriver::class)]
#[UsesClass(SearchOptions::class)]
#[Group('meilisearch')]
final class MeilisearchDriverTest extends TestCase
{
    private const string HOST = 'http://meilisearch:7700';

    private const string TEST_INDEX = 'test_articles';

    private MeilisearchDriver $driver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('MEILISEARCH_HOST');
        $resolvedHost = is_string($host) && $host !== '' ? $host : self::HOST;

        try {
            $this->driver = new MeilisearchDriver($resolvedHost);
            $this->driver->flush(self::TEST_INDEX);
        } catch (Throwable $e) {
            $this->markTestSkipped('Meilisearch not reachable: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        try {
            $this->driver->flush(self::TEST_INDEX);
        } catch (Throwable) {
            // best effort cleanup
        }

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_index_and_search_document(): void
    {
        $this->driver->index(self::TEST_INDEX, 1, ['id' => 1, 'title' => 'Hello World']);

        // Meilisearch indexes asynchronously; allow a short settling time.
        usleep(200_000);

        $result = $this->driver->search(self::TEST_INDEX, 'Hello', new SearchOptions());

        $this->assertGreaterThanOrEqual(1, $result->total);
    }

    /**
     * @return void
     */
    public function test_remove_document(): void
    {
        $this->driver->index(self::TEST_INDEX, 99, ['id' => 99, 'title' => 'To Delete']);
        usleep(200_000);
        $this->driver->remove(self::TEST_INDEX, 99);
        usleep(200_000);

        $result = $this->driver->search(self::TEST_INDEX, 'To Delete', new SearchOptions());

        $this->assertSame(0, $result->total);
    }

    /**
     * @return void
     */
    public function test_flush_clears_index(): void
    {
        $this->driver->index(self::TEST_INDEX, 1, ['id' => 1, 'title' => 'One']);
        $this->driver->index(self::TEST_INDEX, 2, ['id' => 2, 'title' => 'Two']);
        usleep(200_000);

        $this->driver->flush(self::TEST_INDEX);
        usleep(300_000);

        $result = $this->driver->search(self::TEST_INDEX, '', new SearchOptions());

        $this->assertSame(0, $result->total);
    }

    /**
     * @return void
     */
    public function test_search_with_options(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->index(self::TEST_INDEX, $i, ['id' => $i, 'title' => "Document {$i}"]);
        }

        usleep(500_000);

        $result = $this->driver->search(
            self::TEST_INDEX,
            '',
            (new SearchOptions())->withLimit(2)->withOffset(0),
        );

        $this->assertCount(2, $result->hits);
    }

    /**
     * @return void
     */
    public function test_throws_search_exception_on_invalid_host(): void
    {
        $badDriver = new MeilisearchDriver('http://localhost:1');

        $this->expectException(SearchException::class);
        $badDriver->search('idx', 'query', new SearchOptions());
    }
}
