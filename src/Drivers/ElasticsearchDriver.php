<?php

declare(strict_types=1);

namespace EzPhp\Search\Drivers;

use EzPhp\Search\SearchDriverInterface;
use EzPhp\Search\SearchException;
use EzPhp\Search\SearchHit;
use EzPhp\Search\SearchOptions;
use EzPhp\Search\SearchResult;
use stdClass;

/**
 * Class ElasticsearchDriver
 *
 * Search driver that communicates with an Elasticsearch (or OpenSearch) instance
 * via its REST API.
 *
 * An empty query uses a match_all query to return all documents.
 * A non-empty query uses multi_match across all fields ("*").
 *
 * Configuration (via SearchServiceProvider / config/search.php):
 *   search.driver = elasticsearch
 *   search.elasticsearch.host     = http://elasticsearch:9200
 *   search.elasticsearch.user     = <username-or-empty>
 *   search.elasticsearch.password = <password-or-empty>
 *
 * @package EzPhp\Search\Drivers
 */
final class ElasticsearchDriver implements SearchDriverInterface
{
    /**
     * ElasticsearchDriver Constructor
     *
     * @param string $host     Elasticsearch base URL, e.g. "http://elasticsearch:9200".
     * @param string $username Optional HTTP Basic Auth username.
     * @param string $password Optional HTTP Basic Auth password.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username = '',
        private readonly string $password = '',
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
        $this->request('PUT', "/{$indexName}/_doc/{$id}", $document);
    }

    /**
     * @param string     $indexName
     * @param string|int $id
     *
     * @return void
     */
    public function remove(string $indexName, string|int $id): void
    {
        $this->request('DELETE', "/{$indexName}/_doc/{$id}");
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
        $esQuery = $query === ''
            ? ['match_all' => new stdClass()]
            : ['multi_match' => ['query' => $query, 'fields' => ['*']]];

        $body = [
            'from' => $options->offset,
            'size' => $options->limit,
            'query' => $esQuery,
        ];

        if ($options->sort !== []) {
            $body['sort'] = $options->sort;
        }

        $response = $this->request('POST', "/{$indexName}/_search", $body);

        if (!is_array($response)) {
            throw new SearchException('Unexpected response from Elasticsearch: expected JSON object.');
        }

        $hitsEnvelope = isset($response['hits']) && is_array($response['hits']) ? $response['hits'] : [];
        $rawHits = isset($hitsEnvelope['hits']) && is_array($hitsEnvelope['hits']) ? $hitsEnvelope['hits'] : [];

        $total = 0;

        if (isset($hitsEnvelope['total'])) {
            $totalField = $hitsEnvelope['total'];

            if (is_array($totalField) && isset($totalField['value']) && is_int($totalField['value'])) {
                $total = $totalField['value'];
            } elseif (is_int($totalField)) {
                $total = $totalField;
            }
        }

        $took = isset($response['took']) && is_int($response['took']) ? $response['took'] : 0;

        $hits = [];

        foreach ($rawHits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $rawId = $hit['_id'] ?? null;
            $id = is_string($rawId) ? $rawId : '';
            $score = isset($hit['_score']) && is_float($hit['_score']) ? $hit['_score'] : 1.0;

            /** @var array<string, mixed> $source */
            $source = isset($hit['_source']) && is_array($hit['_source']) ? $hit['_source'] : [];

            $hits[] = new SearchHit($id, $score, $source);
        }

        return new SearchResult($hits, $total, $took);
    }

    /**
     * Remove all documents from the index using a delete-by-query.
     *
     * @param string $indexName
     *
     * @return void
     */
    public function flush(string $indexName): void
    {
        $this->request('POST', "/{$indexName}/_delete_by_query", [
            'query' => ['match_all' => new stdClass()],
        ]);
    }

    /**
     * Execute an HTTP request against the Elasticsearch REST API.
     *
     * @param non-empty-string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string           $path   API path, e.g. "/articles/_search".
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

        if ($this->username !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
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
            throw new SearchException("Elasticsearch cURL error for {$method} {$url}: {$curlError}");
        }

        if ($statusCode >= 400) {
            throw new SearchException(
                "Elasticsearch returned HTTP {$statusCode} for {$method} {$path}: {$rawResponse}",
            );
        }

        if ($rawResponse === '' || $rawResponse === 'null') {
            return null;
        }

        return json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
    }
}
