# ez-php/search

Full-text search module for [ez-php](https://github.com/ez-php/framework). Provides a driver-based search abstraction with support for Meilisearch and Elasticsearch, event-driven index synchronisation via `ez-php/events`, and an in-memory `NullDriver` for testing.

## Installation

```bash
composer require ez-php/search
```

## Requirements

- PHP 8.5+
- `ez-php/contracts`
- `ez-php/events`
- A running Meilisearch or Elasticsearch instance (or use `NullDriver` for tests)

## Configuration

Add `config/search.php` to your application:

```php
return [
    'driver' => getenv('SEARCH_DRIVER') ?: 'null', // null | meilisearch | elasticsearch

    'meilisearch' => [
        'host' => getenv('MEILISEARCH_HOST') ?: 'http://meilisearch:7700',
        'key'  => getenv('MEILISEARCH_KEY')  ?: '',
    ],

    'elasticsearch' => [
        'host'     => getenv('ELASTICSEARCH_HOST')     ?: 'http://elasticsearch:9200',
        'user'     => getenv('ELASTICSEARCH_USER')     ?: '',
        'password' => getenv('ELASTICSEARCH_PASSWORD') ?: '',
    ],
];
```

Register the provider in `provider/modules.php`:

```php
\EzPhp\Search\SearchServiceProvider::class,
```

## Making Entities Searchable

Implement `SearchableInterface` and use the `Searchable` trait:

```php
use EzPhp\Search\Searchable;
use EzPhp\Search\SearchableInterface;

class Article implements SearchableInterface
{
    use Searchable;

    public function __construct(
        public int    $id,
        public string $title,
        public string $body,
    ) {}

    public function getSearchableKey(): string|int
    {
        return $this->id;
    }

    // Optional overrides:
    // public function searchableAs(): string { return 'articles'; }
    // public function toSearchableArray(): array { return ['id' => $this->id, 'title' => $this->title]; }
}
```

## Basic Usage

```php
use EzPhp\Search\SearchIndex;
use EzPhp\Search\SearchOptions;

$search = $app->make(SearchIndex::class);

$search->add($article);
$search->remove($article);

$result = $search->search('article', 'php tutorial');

foreach ($result->hits as $hit) {
    echo $hit->id . ': ' . $hit->document['title'] . PHP_EOL;
}

$result = $search->search(
    'article',
    'php',
    (new SearchOptions())->withLimit(10)->withOffset(0)->withFilter('status = "published"'),
);

$search->flush('article');
```

## Automatic Index Synchronisation via Events

Wire `SyncSearchIndex` to your ORM model events in a service provider's `boot()`:

```php
use EzPhp\Events\Event;
use EzPhp\Search\Listeners\SyncSearchIndex;
use EzPhp\Search\SearchIndex;

public function boot(): void
{
    $index = $this->app->make(SearchIndex::class);
    Event::listen(ArticleSaved::class, new SyncSearchIndex($index));
    Event::listen(ArticleDeleted::class, new SyncSearchIndex($index, remove: true));
}
```

ORM events must implement `HasSearchableModel`:

```php
use EzPhp\Search\Listeners\HasSearchableModel;
use EzPhp\Search\SearchableInterface;

class ArticleSaved implements EventInterface, HasSearchableModel
{
    public function __construct(private Article $article) {}
    public function getSearchableModel(): SearchableInterface { return $this->article; }
}
```

## Events

| Event | Fired when |
|---|---|
| `DocumentIndexed` | A document was added or replaced |
| `DocumentRemoved` | A document was removed |

## Drivers

| Driver | Class | Notes |
|---|---|---|
| `null` | `NullDriver` | In-memory, for testing — no external service required |
| `meilisearch` | `MeilisearchDriver` | Meilisearch v1.x REST API |
| `elasticsearch` | `ElasticsearchDriver` | Elasticsearch 8.x / OpenSearch REST API |

## Docker

```bash
cp .env.example .env
bash start.sh
docker compose exec app composer full
```
