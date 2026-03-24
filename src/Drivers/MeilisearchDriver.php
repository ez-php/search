<?php

declare(strict_types=1);

namespace EzPhp\Search\Drivers;

use EzPhp\Search\SearchDriverInterface;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;

/**
 * Class MeilisearchDriver
 *
 * Search driver that communicates with a Meilisearch instance via its REST API.
 *
 * Meilisearch is a lightweight, open-source search engine with excellent
 * defaults. Documents are indexed asynchronously by Meilisearch, but the
 * API calls in this driver are synchronous HTTP requests.
 *
 * Configuration (via SearchServiceProvider / config/search.php):
 *   search.driver = meilisearch
 *   search.meilisearch.host = http://meilisearch:7700
 *   search.meilisearch.key  = <master-key-or-empty-for-no-auth>
 *
 * @package EzPhp\Search\Drivers
 */
final class MeilisearchDriver implements SearchDriverInterface
{
    /**
     * MeilisearchDriver Constructor
     *
     * @param string $host   Meilisearch base URL, e.g. "http://meilisearch:7700".
     * @param string $apiKey Optional API key (master key or search-only key).
     */
    public function __construct(
        private readonly string $host,
        private readonly string $apiKey = '',
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
        // Meilisearch identifies documents by a primary key field inside the document.
        $document['id'] = $id;
        $response = $this->request('POST', "/indexes/{$indexName}/documents", [$document]);
        $this->waitForTask($response);
    }

    /**
     * @param string     $indexName
     * @param string|int $id
     *
     * @return void
     */
    public function remove(string $indexName, string|int $id): void
    {
        $response = $this->request('DELETE', "/indexes/{$indexName}/documents/{$id}");
        $this->waitForTask($response);
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
        $body = [
            'q' => $query,
            'offset' => $options->offset,
            'limit' => $options->limit,
        ];

        if ($options->filter !== '') {
            $body['filter'] = $options->filter;
        }

        if ($options->sort !== []) {
            $body['sort'] = $options->sort;
        }

        $response = $this->request('POST', "/indexes/{$indexName}/search", $body);

        if (!is_array($response)) {
            throw new SearchException('Unexpected response from Meilisearch: expected JSON object.');
        }

        $rawHits = isset($response['hits']) && is_array($response['hits']) ? $response['hits'] : [];
        $total = isset($response['estimatedTotalHits']) && is_int($response['estimatedTotalHits'])
            ? $response['estimatedTotalHits']
            : count($rawHits);
        $took = isset($response['processingTimeMs']) && is_int($response['processingTimeMs'])
            ? $response['processingTimeMs']
            : 0;

        $hits = [];

        foreach ($rawHits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $rawId = $hit['id'] ?? null;
            $id = is_string($rawId) || is_int($rawId) ? (string) $rawId : '';

            /** @var array<string, mixed> $hit */
            $hits[] = new SearchHit($id, 1.0, $hit);
        }

        return new SearchResult($hits, $total, $took);
    }

    /**
     * @param string $indexName
     *
     * @return void
     */
    public function flush(string $indexName): void
    {
        $response = $this->request('DELETE', "/indexes/{$indexName}/documents");
        $this->waitForTask($response, ['index_not_found']);
    }

    /**
     * Wait for a Meilisearch task to reach a terminal state (succeeded or failed).
     *
     * Mutations (index, remove, flush) return a task envelope. This method polls
     * GET /tasks/{taskUid} every 50 ms until the task succeeds, fails, or the
     * timeout is exceeded.
     *
     * Tasks that fail with `index_not_found` are treated as success — the index
     * was already absent, so there is nothing to mutate (relevant for flush in tests).
     *
     * @param mixed    $response       The decoded JSON response from a mutation request.
     * @param string[] $ignoredErrors  Task error codes to treat as success.
     *
     * @return void
     *
     * @throws SearchException If the task fails (and the error is not ignored) or the timeout is exceeded.
     */
    private function waitForTask(mixed $response, array $ignoredErrors = []): void
    {
        if (!is_array($response) || !isset($response['taskUid']) || !is_int($response['taskUid'])) {
            return;
        }

        $taskUid = $response['taskUid'];
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $task = $this->request('GET', "/tasks/{$taskUid}");

            if (!is_array($task) || !isset($task['status']) || !is_string($task['status'])) {
                return;
            }

            if ($task['status'] === 'succeeded') {
                return;
            }

            if ($task['status'] === 'failed') {
                $errorCode = isset($task['error']) && is_array($task['error']) && isset($task['error']['code']) && is_string($task['error']['code'])
                    ? $task['error']['code']
                    : '';

                if ($ignoredErrors !== [] && in_array($errorCode, $ignoredErrors, true)) {
                    return;
                }

                $errorMessage = isset($task['error']) && is_array($task['error']) && isset($task['error']['message']) && is_string($task['error']['message'])
                    ? $task['error']['message']
                    : 'unknown error';

                throw new SearchException("Meilisearch task {$taskUid} failed: {$errorMessage}");
            }

            usleep(50_000);
        }

        throw new SearchException("Meilisearch task {$taskUid} did not complete within 10 seconds.");
    }

    /**
     * Execute an HTTP request against the Meilisearch REST API.
     *
     * @param non-empty-string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string           $path   API path, e.g. "/indexes/articles/documents".
     * @param mixed            $body   Optional JSON-serialisable body.
     *
     * @return mixed Decoded JSON response, or null for empty responses.
     *
     * @throws SearchException On cURL errors or non-2xx HTTP responses.
     */
    private function request(string $method, string $path, mixed $body = null): mixed
    {
        $url = rtrim($this->host, '/') . $path;

        $ch = curl_init($url);

        if ($ch === false) {
            throw new SearchException("Failed to initialise cURL for {$method} {$url}.");
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (!is_string($rawResponse)) {
            throw new SearchException("Meilisearch cURL error for {$method} {$url}: {$curlError}");
        }

        if ($statusCode >= 400) {
            throw new SearchException(
                "Meilisearch returned HTTP {$statusCode} for {$method} {$path}: {$rawResponse}",
            );
        }

        if ($rawResponse === '' || $rawResponse === 'null') {
            return null;
        }

        return json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
    }
}
