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

/**
 * Class SearchServiceProvider
 *
 * Binds SearchIndex to the container using the driver configured via
 * config/search.php (or the SEARCH_DRIVER environment variable).
 *
 * Supported drivers: null (default), meilisearch, elasticsearch
 *
 * The EventDispatcher is sourced from the Event static facade so that events
 * fired by SearchIndex reach any listeners registered via EventServiceProvider.
 * If EventServiceProvider is not registered, events are silently ignored.
 *
 * @package EzPhp\Search
 */
final class SearchServiceProvider extends ServiceProvider
{
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
        $host = $config->get('search.meilisearch.host', 'http://meilisearch:7700');
        $key = $config->get('search.meilisearch.key', '');

        return new MeilisearchDriver(
            is_string($host) ? $host : 'http://meilisearch:7700',
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
        $host = $config->get('search.elasticsearch.host', 'http://elasticsearch:9200');
        $user = $config->get('search.elasticsearch.user', '');
        $pass = $config->get('search.elasticsearch.password', '');

        return new ElasticsearchDriver(
            is_string($host) ? $host : 'http://elasticsearch:9200',
            is_string($user) ? $user : '',
            is_string($pass) ? $pass : '',
        );
    }
}
