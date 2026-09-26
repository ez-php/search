<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EzPhp\Search\Drivers\ElasticsearchDriver;
use EzPhp\Search\SearchOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;
use Throwable;

/**
 * Class ElasticsearchDriverTest
 *
 * Integration tests for the Elasticsearch driver, mirroring
 * MeilisearchDriverTest / TypesenseDriverTest. All tests are skipped when the
 * configured Elasticsearch instance is unreachable — no elasticsearch service
 * is defined in this monorepo's docker-compose.yml, so this suite is expected
 * to skip in that environment; run it against a real cluster (e.g. via
 * ELASTICSEARCH_HOST) to exercise it for real.
 *
 * @package Tests\Drivers
 */
#[CoversClass(ElasticsearchDriver::class)]
#[UsesClass(SearchOptions::class)]
#[Group('elasticsearch')]
final class ElasticsearchDriverTest extends TestCase
{
    private const string HOST = 'http://elasticsearch:9200';

    private const string TEST_INDEX = 'test_articles';

    private ElasticsearchDriver $driver;

    /**
     * Why the server was unreachable, cached for the whole class: the first failed
     * probe costs a full connect/DNS timeout, every later test skips immediately.
     */
    private static ?string $unreachable = null;

    private string $resolvedHost = self::HOST;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$unreachable !== null) {
            $this->markTestSkipped(self::$unreachable);
        }

        $host = getenv('ELASTICSEARCH_HOST');
        $user = getenv('ELASTICSEARCH_USER');
        $password = getenv('ELASTICSEARCH_PASSWORD');

        $this->resolvedHost = is_string($host) && $host !== '' ? $host : self::HOST;
        $resolvedUser = is_string($user) ? $user : '';
        $resolvedPassword = is_string($password) ? $password : '';

        try {
            $this->driver = new ElasticsearchDriver($this->resolvedHost, $resolvedUser, $resolvedPassword);
            $this->driver->flush(self::TEST_INDEX);
        } catch (Throwable $e) {
            self::$unreachable = 'Elasticsearch not reachable: ' . $e->getMessage();
            $this->markTestSkipped(self::$unreachable);
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        if (self::$unreachable !== null) {
            return;
        }

        try {
            $this->driver->flush(self::TEST_INDEX);
        } catch (Throwable) {
            // ignore
        }
    }

    public function testIndexAndSearchDocument(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-1', ['title' => 'Hello World', 'body' => 'Test content']);
        $this->refreshIndex();

        $result = $this->driver->search(self::TEST_INDEX, 'Hello', new SearchOptions());

        $this->assertGreaterThanOrEqual(1, $result->total);
        $this->assertNotEmpty($result->hits);
        $this->assertSame('doc-1', $result->hits[0]->id);
    }

    public function testIndexOverwritesExistingDocument(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-1', ['title' => 'Original']);
        $this->driver->index(self::TEST_INDEX, 'doc-1', ['title' => 'Updated']);
        $this->refreshIndex();

        $result = $this->driver->search(self::TEST_INDEX, 'Updated', new SearchOptions());

        $this->assertGreaterThanOrEqual(1, $result->total);
    }

    public function testRemoveDocument(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-2', ['title' => 'ToDelete']);
        $this->refreshIndex();

        $this->driver->remove(self::TEST_INDEX, 'doc-2');
        $this->refreshIndex();

        $result = $this->driver->search(self::TEST_INDEX, 'ToDelete', new SearchOptions());

        $this->assertSame(0, $result->total);
    }

    public function testRemoveNonExistentDocumentDoesNotThrow(): void
    {
        $this->driver->remove(self::TEST_INDEX, 'non-existent-id');

        $this->addToAssertionCount(1);
    }

    public function testFlushNonExistentIndexDoesNotThrow(): void
    {
        $this->driver->flush('nonexistent_index_xyz');

        $this->addToAssertionCount(1);
    }

    public function testFlushEmptiesIndex(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-3', ['title' => 'Before flush']);
        $this->refreshIndex();

        $this->driver->flush(self::TEST_INDEX);
        $this->refreshIndex();

        $result = $this->driver->search(self::TEST_INDEX, 'Before flush', new SearchOptions());

        $this->assertSame(0, $result->total);
    }

    public function testSearchReturnsEmptyResultForMiss(): void
    {
        $this->driver->index(self::TEST_INDEX, 'doc-5', ['title' => 'Something unrelated']);
        $this->refreshIndex();

        $result = $this->driver->search(self::TEST_INDEX, 'zzznomatch', new SearchOptions());

        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->hits);
    }

    public function testSearchWithEmptyQueryMatchesAll(): void
    {
        $this->driver->index(self::TEST_INDEX, 'match-all-1', ['title' => 'Article one']);
        $this->driver->index(self::TEST_INDEX, 'match-all-2', ['title' => 'Article two']);
        $this->refreshIndex();

        $result = $this->driver->search(self::TEST_INDEX, '', new SearchOptions());

        $this->assertGreaterThanOrEqual(2, $result->total);
    }

    public function testSearchWithPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->index(self::TEST_INDEX, "page-doc-{$i}", ['title' => "Article number {$i}"]);
        }
        $this->refreshIndex();

        $options = new SearchOptions(offset: 0, limit: 2);
        $result = $this->driver->search(self::TEST_INDEX, 'Article', $options);

        $this->assertLessThanOrEqual(2, count($result->hits));
        $this->assertGreaterThanOrEqual(2, $result->total);
    }

    /**
     * Elasticsearch indexing is near-real-time, not immediate — a document is
     * not guaranteed to be searchable until the index is refreshed (or the
     * default ~1s refresh interval elapses). Force a refresh so assertions
     * immediately after index()/remove() are deterministic.
     *
     * @return void
     */
    private function refreshIndex(): void
    {
        $ch = curl_init($this->resolvedHost . '/' . self::TEST_INDEX . '/_refresh');

        if ($ch === false) {
            return;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
    }
}
