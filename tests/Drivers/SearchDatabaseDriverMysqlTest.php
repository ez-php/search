<?php

declare(strict_types=1);

namespace Tests\Drivers;

use EzPhp\Database\Database;
use EzPhp\Search\Drivers\DatabaseDriver;
use EzPhp\Search\Drivers\DocumentFilter;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * DatabaseDriver's MySQL branch: InnoDB FULLTEXT DDL and MATCH … AGAINST in
 * boolean mode. Skipped unless DB_HOST is set; the table is dropped around each test.
 */
#[CoversClass(DatabaseDriver::class)]
#[UsesClass(DocumentFilter::class)]
#[UsesClass(SearchHit::class)]
#[UsesClass(SearchOptions::class)]
#[UsesClass(SearchResult::class)]
final class SearchDatabaseDriverMysqlTest extends TestCase
{
    private const string TABLE = 'ez_search_documents_test';

    private ?Database $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $host = (string) getenv('DB_HOST');

        if ($host === '') {
            self::markTestSkipped('MySQL not available — set DB_HOST to run this test.');
        }

        try {
            $this->db = new Database(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, (string) (getenv('DB_PORT') ?: '3306'), (string) (getenv('DB_TESTING_DATABASE') ?: getenv('DB_DATABASE'))),
                (string) getenv('DB_USERNAME'),
                (string) getenv('DB_PASSWORD'),
            );
        } catch (PDOException $e) {
            self::markTestSkipped("MySQL not reachable: {$e->getMessage()}");
        }

        $this->db->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function tearDown(): void
    {
        $this->db?->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        parent::tearDown();
    }

    public function test_fulltext_search_on_mysql(): void
    {
        self::assertNotNull($this->db);
        $driver = new DatabaseDriver($this->db, self::TABLE);
        $driver->index('articles', 1, ['title' => 'PHP performance tips', 'body' => 'opcache and preloading', 'status' => 'published']);
        $driver->index('articles', 2, ['title' => 'Deploying PHP', 'body' => 'containers and php tuning', 'status' => 'draft']);
        $driver->index('articles', 2, ['title' => 'Deploying PHP', 'body' => 'containers and php tuning', 'status' => 'published']);

        $ids = static fn (SearchResult $r): array => array_map(static fn (SearchHit $h): int|string => $h->id, $r->hits);

        self::assertSame([1], $ids($driver->search('articles', 'perf opcache', new SearchOptions())));
        self::assertSame([2, 1], $ids($driver->search('articles', 'php', new SearchOptions(sort: ['title:asc']))));
        self::assertSame(2, $driver->search('articles', '', new SearchOptions(filter: 'status = published'))->total);

        $driver->flush('articles');
        self::assertSame(0, $driver->search('articles', 'php', new SearchOptions())->total);
    }
}
