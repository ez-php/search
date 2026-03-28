<?php

declare(strict_types=1);

namespace EzPhp\Search\Drivers;

use EzPhp\Search\SearchDriverInterface;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;

/**
 * Class TypesenseDriver
 *
 * Search driver that communicates with a Typesense instance via its REST API.
 *
 * Collections are created automatically on first index using a wildcard
 * auto-schema (`"fields": [{"name": ".*", "type": "auto"}]`). Explicit
 * schema management (field types, sorting, facets) must be done via the
 * Typesense UI or API directly — it is out of scope for this driver.
 *
 * All document IDs are cast to string because Typesense requires the `id`
 * field to be a string.
 *
 * Configuration (via SearchServiceProvider / config/search.php):
 *   search.driver = typesense
 *   search.typesense.host   = http://typesense:8108
 *   search.typesense.key    = <api-key>
 *
 * @package EzPhp\Search\Drivers
 */
final class TypesenseDriver implements SearchDriverInterface
{
    /**
     * TypesenseDriver Constructor
     *
     * @param string $host   Typesense base URL, e.g. "http://typesense:8108".
     * @param string $apiKey Typesense API key with read/write permissions.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $apiKey,
    ) {
    }

    /**
     * @param string               $indexName
     * @param string|int           $id
     * @param array<string, mixed> $document
     *
     * @return void
     */
    public function index(string $indexName, string|int $id, array $document): void
    {
        $document['id'] = (string) $id;

        try {
            $this->request('POST', "/collections/{$indexName}/documents", $document, ['action' => 'upsert']);
        } catch (SearchException $e) {
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }

            // Collection does not exist — create it with wildcard auto-schema and retry.
            $this->createCollection($indexName);
            $this->request('POST', "/collections/{$indexName}/documents", $document, ['action' => 'upsert']);
        }
    }

    /**
     * @param string     $indexName
     * @param string|int $id
     *
     * @return void
     */
    public function remove(string $indexName, string|int $id): void
    {
        try {
            $this->request('DELETE', "/collections/{$indexName}/documents/{$id}");
        } catch (SearchException $e) {
            // Document already absent — treat as a no-op.
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    /**
     * @param string        $indexName
     * @param string        $query
     * @param SearchOptions $options
     *
     * @return SearchResult
     */
    public function search(string $indexName, string $query, SearchOptions $options): SearchResult
    {
        // Typesense uses 1-based pages. Derive the page from offset + limit.
        $page = $options->limit > 0
            ? (int) floor($options->offset / $options->limit) + 1
            : 1;

        $params = [
            'q' => $query === '' ? '*' : $query,
            'query_by' => '*',
            'per_page' => $options->limit,
            'page' => $page,
        ];

        if ($options->filter !== '') {
            $params['filter_by'] = $options->filter;
        }

        if ($options->sort !== []) {
            $params['sort_by'] = implode(',', $options->sort);
        }

        $response = $this->request('GET', "/collections/{$indexName}/documents/search", null, $params);

        if (!is_array($response)) {
            throw new SearchException('Unexpected response from Typesense: expected JSON object.');
        }

        $rawHits = isset($response['hits']) && is_array($response['hits']) ? $response['hits'] : [];
        $total = isset($response['found']) && is_int($response['found']) ? $response['found'] : count($rawHits);
        $took = isset($response['search_time_ms']) && is_int($response['search_time_ms'])
            ? $response['search_time_ms']
            : 0;

        $hits = [];

        foreach ($rawHits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            /** @var array<string, mixed> $doc */
            $doc = isset($hit['document']) && is_array($hit['document']) ? $hit['document'] : [];
            $rawId = $doc['id'] ?? null;
            $hitId = is_string($rawId) || is_int($rawId) ? (string) $rawId : '';
            $score = isset($hit['text_match']) && is_int($hit['text_match'])
                ? (float) $hit['text_match']
                : 1.0;

            $hits[] = new SearchHit($hitId, $score, $doc);
        }

        return new SearchResult($hits, $total, $took);
    }

    /**
     * Drop the entire collection, removing all documents and the schema.
     *
     * Note: the collection is fully deleted — the next index() call will
     * re-create it using the wildcard auto-schema.
     *
     * @param string $indexName
     *
     * @return void
     */
    public function flush(string $indexName): void
    {
        try {
            $this->request('DELETE', "/collections/{$indexName}");
        } catch (SearchException $e) {
            // Collection already absent — no-op.
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    /**
     * Create a Typesense collection with a wildcard auto-schema.
     *
     * @param string $name Collection name.
     *
     * @return void
     */
    private function createCollection(string $name): void
    {
        $this->request('POST', '/collections', [
            'name' => $name,
            'fields' => [['name' => '.*', 'type' => 'auto']],
            'enable_nested_fields' => true,
        ]);
    }

    /**
     * Execute an HTTP request against the Typesense REST API.
     *
     * @param non-empty-string     $method     HTTP method (GET, POST, DELETE).
     * @param string               $path       API path, e.g. "/collections/articles/documents".
     * @param mixed                $body       Optional JSON-serialisable request body.
     * @param array<string, mixed> $queryParams Optional URL query parameters.
     *
     * @return mixed Decoded JSON response, or null for empty responses.
     *
     * @throws SearchException On cURL errors or non-2xx HTTP responses.
     */
    private function request(string $method, string $path, mixed $body = null, array $queryParams = []): mixed
    {
        $url = rtrim($this->host, '/') . $path;

        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new SearchException("Failed to initialise cURL for {$method} {$url}.");
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-TYPESENSE-API-KEY: ' . $this->apiKey,
        ];

        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if (!is_string($rawResponse)) {
            throw new SearchException("Typesense cURL error for {$method} {$url}: {$curlError}");
        }

        if ($statusCode >= 400) {
            throw new SearchException(
                "Typesense returned HTTP {$statusCode} for {$method} {$path}: {$rawResponse}",
            );
        }

        if ($rawResponse === '' || $rawResponse === 'null') {
            return null;
        }

        return json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
    }
}
