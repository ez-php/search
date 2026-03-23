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
        $this->request('POST', "/indexes/{$indexName}/documents", [$document]);
    }

    /**
     * @param string     $indexName
     * @param string|int $id
     *
     * @return void
     */
    public function remove(string $indexName, string|int $id): void
    {
        $this->request('DELETE', "/indexes/{$indexName}/documents/{$id}");
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
        $this->request('DELETE', "/indexes/{$indexName}/documents");
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
        curl_close($ch);

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
