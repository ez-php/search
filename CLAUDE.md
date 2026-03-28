# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "0.*"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| **next free** | **3310** | **6381** |

Only set a port for services the module actually uses. Modules without external services need no port config.

### 4 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/search

Full-text search module for ez-php. Provides a driver-based abstraction over Meilisearch and Elasticsearch, event-driven index synchronisation via `ez-php/events`, and an in-memory `NullDriver` for testing.

---

## Source Structure

```
src/
├── SearchableInterface.php         — contract for indexable entities
├── Searchable.php                  — trait: default searchableAs() and toSearchableArray()
├── SearchDriverInterface.php       — contract for search engine adapters
├── SearchOptions.php               — immutable value object: offset, limit, filter, sort
├── SearchHit.php                   — immutable value object: id, score, document
├── SearchResult.php                — immutable value object: hits list, total, took
├── SearchException.php             — base exception (extends EzPhpException)
├── SearchIndex.php                 — main orchestrator: add/remove/search/flush + events
├── SearchServiceProvider.php       — binds SearchIndex; reads config/search.php
├── Drivers/
│   ├── NullDriver.php              — in-memory driver for testing; simple substring search
│   ├── MeilisearchDriver.php       — Meilisearch REST API adapter (native cURL)
│   ├── ElasticsearchDriver.php     — Elasticsearch REST API adapter (native cURL)
│   └── TypesenseDriver.php         — Typesense REST API adapter (native cURL); auto-creates collections
├── Events/
│   ├── DocumentIndexed.php         — fired after a document is added/replaced
│   └── DocumentRemoved.php         — fired after a document is removed
└── Listeners/
    ├── HasSearchableModel.php      — interface for events carrying a SearchableInterface
    └── SyncSearchIndex.php         — ListenerInterface: indexes or removes on model events
```

---

## Key Classes and Responsibilities

**`SearchableInterface`** — The entity contract. Three methods: `searchableAs()` (index name), `getSearchableKey()` (document ID), `toSearchableArray()` (document data).

**`Searchable` (trait)** — Provides `searchableAs()` (lowercased short class name) and `toSearchableArray()` (all public properties via reflection). Classes using this trait must implement `getSearchableKey()` themselves. Annotated `@phpstan-require-implements SearchableInterface`.

**`SearchIndex`** — Single entry point for all search operations. Constructor takes `SearchDriverInterface` and optional `EventDispatcher`. Fires `DocumentIndexed`/`DocumentRemoved` events after mutations if a dispatcher is present.

**`SearchServiceProvider`** — Reads `search.driver` config, creates the appropriate driver, and binds `SearchIndex`. Uses `Event::getDispatcher()` so that events flow through the EventServiceProvider's wired dispatcher when both providers are registered.

**`NullDriver`** — Stores documents in a PHP array. `search()` performs case-insensitive substring matching across scalar values. `all()` returns the raw store for test assertions.

**`MeilisearchDriver` / `ElasticsearchDriver` / `TypesenseDriver`** — REST API adapters using native PHP cURL. No external HTTP package dependency. All API errors throw `SearchException`.

**`TypesenseDriver`** — Collections are created automatically on first `index()` using a wildcard auto-schema (`fields: [{"name": ".*", "type": "auto"}]`). `flush()` drops the entire collection (schema + documents); the next `index()` call recreates it. IDs are cast to string (Typesense requirement). Authentication via `X-TYPESENSE-API-KEY` header.

**`SyncSearchIndex`** — Listens for any event implementing `HasSearchableModel` and calls `add()` or `remove()` on `SearchIndex`. Constructed with `$remove = true` for delete events.

---

## Design Decisions

- **Driver pattern** — all engine specifics live in a driver; `SearchIndex` knows nothing about the underlying API.
- **Native cURL in drivers** — avoids pulling in `ez-php/http-client` as a hard dependency; search engines expose simple REST APIs that don't need a fluent client wrapper.
- **`Event::getDispatcher()` in provider** — always returns a valid dispatcher (creating a standalone one if `EventServiceProvider` is not registered). Events fired with no listeners are silently dropped. This avoids try/catch or has() checks on the container.
- **`Searchable` trait + `@phpstan-require-implements`** — satisfies PHPStan level 9 for trait usage; `getSearchableKey()` is deliberately NOT provided by the trait to force explicit implementation.
- **`HasSearchableModel` interface** — decouples `SyncSearchIndex` from any specific ORM or event class; the application wires the listener to its own model events.
- **Meilisearch as primary driver** — lighter than Elasticsearch, better defaults for small-to-medium datasets. Elasticsearch added as an alternative for teams already running it.

---

## Testing Approach

Unit tests use `NullDriver` exclusively — no external services required.

Integration tests (`#[Group('meilisearch')]`) require a running Meilisearch instance. They skip automatically (via `markTestSkipped`) when Meilisearch is unreachable.

Integration tests for Typesense (`#[Group('typesense')]`) require a running Typesense instance. They skip automatically when Typesense is unreachable.

In the standalone Docker environment:
- `meilisearch` service is defined in `docker-compose.yml` (port 7700)
- All tests run inside the `app` container

In the CI compatibility matrix:
- Only unit tests run (`--exclude-group meilisearch,elasticsearch,typesense`)

### Infrastructure requirements

| Service | Purpose | Port |
|---|---|---|
| Meilisearch | Integration tests | 7700 |

No MySQL or Redis dependency.

---

## What Does NOT Belong Here

- ORM integration code — `SyncSearchIndex` is intentionally ORM-agnostic; wiring to model events belongs in the application layer
- Ranking/relevance configuration — managed via Meilisearch/Elasticsearch UI or API directly
- Multi-tenant index management — out of scope; manage index names per tenant in application code
- Search analytics or logging — handled by separate modules
