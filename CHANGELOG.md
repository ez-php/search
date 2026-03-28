# Changelog

All notable changes to `ez-php/search` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.0.1] — 2026-03-25

### Changed
- Tightened all `ez-php/*` dependency constraints from `"*"` to `"^1.0"` for predictable resolution

---

## [v1.0.0] — 2026-03-24

### Added
- `SearchDriverInterface` — driver contract with `index()`, `remove()`, `search()`, and `flush()` methods
- `MeilisearchDriver` — indexes and searches documents via the Meilisearch HTTP API
- `ElasticsearchDriver` — indexes and searches documents via the Elasticsearch HTTP API
- `NullDriver` — silently discards all operations; useful in testing
- `SearchableInterface` — marks a model as searchable with `toSearchableArray()` and `searchableAs()` contracts
- `Searchable` — trait that implements `SearchableInterface` and provides `search()`, `indexDocument()`, and `removeDocument()` helpers
- `SearchIndex` — value object describing an index name and its field configuration
- `SearchOptions` — value object for search parameters: query string, filters, sort, limit, and offset
- `SearchResult` — paginated result set containing `SearchHit` instances with document data and relevance scores
- `SearchHit` — value object with document `id`, `score`, and raw `data` array
- `DocumentIndexed` / `DocumentRemoved` — events fired after successful index and removal operations for event-driven synchronisation
- `SearchServiceProvider` — resolves the configured driver and binds it as `SearchDriverInterface`
- `SearchException` for driver connectivity and query failures
