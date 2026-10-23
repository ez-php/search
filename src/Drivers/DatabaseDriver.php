<?php

declare(strict_types=1);

namespace EzPhp\Search\Drivers;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Search\SearchDriverInterface;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;
use PDO;
use Throwable;

/**
 * Class DatabaseDriver
 *
 * Full-text search on the application's own database, for small deployments
 * that run MySQL (or SQLite) but no search server:
 *
 * - MySQL / MariaDB: an InnoDB table with a FULLTEXT index on the document text,
 *   queried with MATCH … AGAINST in boolean mode (every term required, prefix
 *   match); the relevance is the hit score.
 * - SQLite: an FTS5 virtual table queried with MATCH (every term required,
 *   prefix match); the score is -bm25().
 *
 * The table (default `search_documents`) is created on first use. Each document
 * is stored as JSON next to a `content` column holding its scalar values; one
 * table serves every index (`index_name` column).
 *
 * Filters and sort (SearchOptions) are evaluated in PHP on the stored attributes,
 * with the same `field = value` / `field:asc|desc` rules as NullDriver, so every
 * match is loaded before paging — fine for thousands of documents, not millions.
 * Without a sort, hits are ordered by relevance. No typo tolerance, facets or
 * ranking tuning; MySQL's FULLTEXT ignores words shorter than
 * `innodb_ft_min_token_size` (default 3) and its stopword list.
 *
 * @package EzPhp\Search\Drivers
 */
final class DatabaseDriver implements SearchDriverInterface
{
    private readonly string $driver;

    private bool $tableChecked = false;

    /**
     * @param DatabaseInterface $db
     * @param string            $table Table name; interpolated into SQL, so limited to [A-Za-z0-9_].
     *
     * @throws SearchException On an invalid table name or a database other than MySQL/SQLite.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $table = 'search_documents',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new SearchException("Invalid search table name '{$table}': only [A-Za-z0-9_] are allowed.");
        }

        $driver = $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new SearchException(sprintf('The database search driver supports MySQL and SQLite, not "%s".', is_string($driver) ? $driver : '?'));
        }

        $this->driver = $driver;
    }

    /**
     * {@inheritDoc}
     */
    public function index(string $indexName, string|int $id, array $document): void
    {
        $this->ensureTable();

        try {
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $content = self::content($document);

            $this->db->transaction(function () use ($indexName, $id, $json, $content): void {
                // FTS5 tables have no unique key, so both dialects replace by delete + insert.
                $this->db->execute("DELETE FROM {$this->table} WHERE index_name = ? AND doc_id = ?", [$indexName, (string) $id]);
                $this->db->execute(
                    "INSERT INTO {$this->table} (index_name, doc_id, document, content) VALUES (?, ?, ?, ?)",
                    [$indexName, (string) $id, $json, $content],
                );
            });
        } catch (Throwable $e) {
            throw new SearchException("Failed to index document {$id} in \"{$indexName}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $indexName, string|int $id): void
    {
        $this->ensureTable();

        try {
            $this->db->execute("DELETE FROM {$this->table} WHERE index_name = ? AND doc_id = ?", [$indexName, (string) $id]);
        } catch (Throwable $e) {
            throw new SearchException("Failed to remove document {$id} from \"{$indexName}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function search(string $indexName, string $query, SearchOptions $options): SearchResult
    {
        $this->ensureTable();
        $start = microtime(true);
        $conditions = DocumentFilter::parse($options->filter);
        $match = self::matchExpression($query, $this->driver);

        try {
            $rows = $match === null
                ? $this->db->query("SELECT doc_id, document, 0 AS score FROM {$this->table} WHERE index_name = ?", [$indexName])
                : $this->db->query($this->matchSql(), $this->driver === 'mysql' ? [$match, $indexName, $match] : [$match, $indexName]);
        } catch (Throwable $e) {
            throw new SearchException("Search in \"{$indexName}\" failed: {$e->getMessage()}", 0, $e);
        }

        $hits = [];

        foreach ($rows as $row) {
            $document = json_decode(is_string($row['document'] ?? null) ? $row['document'] : '[]', true);
            $document = is_array($document) ? $document : [];

            /** @var array<string, mixed> $document */
            if (!DocumentFilter::passes($document, $conditions)) {
                continue;
            }

            $id = is_scalar($row['doc_id'] ?? null) ? (string) $row['doc_id'] : '';
            $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
            // Ids are stored as strings; give back an int where it round-trips exactly ("7", not "007").
            $hits[] = new SearchHit((string) (int) $id === $id ? (int) $id : $id, $score, $document);
        }

        if ($options->sort !== []) {
            $hits = DocumentFilter::sort($hits, $options->sort);
        } elseif ($match !== null) {
            usort($hits, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);
        }

        $total = count($hits);
        $page = array_slice($hits, $options->offset, $options->limit > 0 ? $options->limit : null);

        return new SearchResult($page, $total, (int) round((microtime(true) - $start) * 1000));
    }

    /**
     * {@inheritDoc}
     */
    public function flush(string $indexName): void
    {
        $this->ensureTable();

        try {
            $this->db->execute("DELETE FROM {$this->table} WHERE index_name = ?", [$indexName]);
        } catch (Throwable $e) {
            throw new SearchException("Failed to flush \"{$indexName}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Create the documents table (MySQL: InnoDB + FULLTEXT; SQLite: FTS5) if needed.
     *
     * @return void
     */
    public function ensureTable(): void
    {
        if ($this->tableChecked) {
            return;
        }

        try {
            $this->db->getPdo()->exec($this->driver === 'mysql'
                ? "CREATE TABLE IF NOT EXISTS {$this->table} (
                       index_name VARCHAR(191) NOT NULL,
                       doc_id     VARCHAR(191) NOT NULL,
                       document   JSON         NOT NULL,
                       content    MEDIUMTEXT   NOT NULL,
                       PRIMARY KEY (index_name, doc_id),
                       FULLTEXT KEY {$this->table}_content_ft (content)
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                : "CREATE VIRTUAL TABLE IF NOT EXISTS {$this->table} USING fts5(
                       index_name UNINDEXED,
                       doc_id     UNINDEXED,
                       document   UNINDEXED,
                       content
                   )");
        } catch (Throwable $e) {
            throw new SearchException("Failed to create the {$this->table} table: {$e->getMessage()}", 0, $e);
        }

        $this->tableChecked = true;
    }

    /**
     * @return string
     */
    private function matchSql(): string
    {
        return $this->driver === 'mysql'
            ? "SELECT doc_id, document, MATCH (content) AGAINST (? IN BOOLEAN MODE) AS score
               FROM {$this->table}
               WHERE index_name = ? AND MATCH (content) AGAINST (? IN BOOLEAN MODE)"
            : "SELECT doc_id, document, -bm25({$this->table}) AS score
               FROM {$this->table}
               WHERE {$this->table} MATCH ? AND index_name = ?";
    }

    /**
     * Turn a free-text query into a dialect match expression requiring every term
     * (as a prefix); null for a query without terms (= return every document).
     *
     * @param string $query
     * @param string $driver 'mysql' or 'sqlite'
     *
     * @return string|null
     */
    private static function matchExpression(string $query, string $driver): ?string
    {
        // Keep letters/digits only: operators of either syntax (+-<>()~*"@:^) must not leak in.
        $terms = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($terms === []) {
            return null;
        }

        return $driver === 'mysql'
            ? implode(' ', array_map(static fn (string $t): string => '+' . $t . '*', $terms))
            : 'content : ' . implode(' AND ', array_map(static fn (string $t): string => '"' . $t . '"*', $terms));
    }

    /**
     * The searchable text: every scalar value (nested arrays flattened), space-joined.
     *
     * @param array<mixed> $document
     *
     * @return string
     */
    private static function content(array $document): string
    {
        $parts = [];

        array_walk_recursive($document, static function (mixed $value) use (&$parts): void {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $parts[] = (string) $value;
            }
        });

        return implode(' ', $parts);
    }
}
