<?php

declare(strict_types=1);

namespace EzPhp\Search;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Events\Event;
use EzPhp\Search\Drivers\ElasticsearchDriver;
use EzPhp\Search\Drivers\MeilisearchDriver;
use EzPhp\Search\Drivers\NullDriver;
use EzPhp\Search\Drivers\TypesenseDriver;

/**
 * Class SearchServiceProvider
 *
 * Binds SearchIndex to the container using the driver configured via
 * config/search.php (or the SEARCH_DRIVER environment variable).
 *
 * Supported drivers: null (default), meilisearch, elasticsearch, typesense
 *
 * The EventDispatcher is sourced from the Event static facade so that events
 * fired by SearchIndex reach any listeners registered via EventServiceProvider.
 * If EventServiceProvider is not registered, events are silently ignored.
 *
 * @package EzPhp\Search
 */
final class SearchServiceProvider extends ServiceProvider
{
    private const string DEFAULT_MEILISEARCH_HOST = 'http://meilisearch:7700';

    private const string DEFAULT_TYPESENSE_HOST = 'http://typesense:8108';

    private const string DEFAULT_ELASTICSEARCH_HOST = 'http://elasticsearch:9200';

    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(SearchIndex::class, function (ContainerInterface $app): SearchIndex {
            $config = $app->make(ConfigInterface::class);
            $driverName = $config->get('search.driver', 'null');
            $driverName = is_string($driverName) ? $driverName : 'null';

            $driver = match ($driverName) {
                'meilisearch' => $this->makeMeilisearch($config),
                'elasticsearch' => $this->makeElasticsearch($config),
                'typesense' => $this->makeTypesense($config),
                default => new NullDriver(),
            };

            // The Event facade always provides a dispatcher. If EventServiceProvider is
            // registered, this is the same instance wired to all listeners. If not, it is
            // a standalone dispatcher with no listeners — events fire but are silently dropped.
            return new SearchIndex($driver, Event::getDispatcher());
        });
    }

    /**
     * @param ConfigInterface $config
     *
     * @return MeilisearchDriver
     */
    private function makeMeilisearch(ConfigInterface $config): MeilisearchDriver
    {
        $host = $config->get('search.meilisearch.host', self::DEFAULT_MEILISEARCH_HOST);
        $key = $config->get('search.meilisearch.key', '');

        return new MeilisearchDriver(
            is_string($host) ? $host : self::DEFAULT_MEILISEARCH_HOST,
            is_string($key) ? $key : '',
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return TypesenseDriver
     */
    private function makeTypesense(ConfigInterface $config): TypesenseDriver
    {
        $host = $config->get('search.typesense.host', self::DEFAULT_TYPESENSE_HOST);
        $key = $config->get('search.typesense.key', '');

        return new TypesenseDriver(
            is_string($host) ? $host : self::DEFAULT_TYPESENSE_HOST,
            is_string($key) ? $key : '',
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return ElasticsearchDriver
     */
    private function makeElasticsearch(ConfigInterface $config): ElasticsearchDriver
    {
        $host = $config->get('search.elasticsearch.host', self::DEFAULT_ELASTICSEARCH_HOST);
        $user = $config->get('search.elasticsearch.user', '');
        $pass = $config->get('search.elasticsearch.password', '');

        return new ElasticsearchDriver(
            is_string($host) ? $host : self::DEFAULT_ELASTICSEARCH_HOST,
            is_string($user) ? $user : '',
            is_string($pass) ? $pass : '',
        );
    }
}
