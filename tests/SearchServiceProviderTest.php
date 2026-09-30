<?php

declare(strict_types=1);

namespace Tests\Search;

use EzPhp\Application\Application;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventServiceProvider;
use EzPhp\Search\Drivers\ElasticsearchDriver;
use EzPhp\Search\Drivers\MeilisearchDriver;
use EzPhp\Search\Drivers\NullDriver;
use EzPhp\Search\Drivers\TypesenseDriver;
use EzPhp\Search\SearchDriverInterface;
use EzPhp\Search\SearchIndex;
use EzPhp\Search\SearchServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\ApplicationTestCase;

/**
 * Class SearchServiceProviderTest
 *
 * @package Tests\Search
 */
#[CoversClass(SearchServiceProvider::class)]
#[UsesClass(SearchIndex::class)]
#[UsesClass(NullDriver::class)]
#[UsesClass(MeilisearchDriver::class)]
#[UsesClass(ElasticsearchDriver::class)]
#[UsesClass(TypesenseDriver::class)]
final class SearchServiceProviderTest extends ApplicationTestCase
{
    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(EventServiceProvider::class);
        $app->register(SearchServiceProvider::class);
    }

    /**
     * @return void
     */
    public function test_search_index_is_bound_in_container(): void
    {
        $this->assertInstanceOf(SearchIndex::class, $this->app()->make(SearchIndex::class));
    }

    /**
     * @return void
     */
    public function test_default_driver_is_null_driver(): void
    {
        $index = $this->app()->make(SearchIndex::class);

        $this->assertInstanceOf(NullDriver::class, $index->getDriver());
    }

    /**
     * @return void
     */
    public function test_resolves_same_instance_on_repeated_make(): void
    {
        $first = $this->app()->make(SearchIndex::class);
        $second = $this->app()->make(SearchIndex::class);

        $this->assertSame($first, $second);
    }

    /**
     * @return void
     */
    public function test_search_index_uses_event_dispatcher_when_events_registered(): void
    {
        $index = $this->app()->make(SearchIndex::class);
        $dispatcher = $this->app()->make(EventDispatcher::class);

        // The index was built with Event::getDispatcher() which is the same instance
        // as the one EventServiceProvider registered.
        $this->assertSame($dispatcher, \EzPhp\Events\Event::getDispatcher());
    }

    /**
     * @return array<string, array{mixed, class-string<SearchDriverInterface>}>
     */
    public static function drivers(): array
    {
        return [
            'meilisearch' => ['meilisearch', MeilisearchDriver::class],
            'elasticsearch' => ['elasticsearch', ElasticsearchDriver::class],
            'typesense' => ['typesense', TypesenseDriver::class],
            'null' => ['null', NullDriver::class],
            'unknown falls back to null' => ['solr', NullDriver::class],
            'non-string falls back to null' => [true, NullDriver::class],
        ];
    }

    /**
     * Each `search.driver` value resolves to its driver class. Built against a
     * minimal container so the config can be set per case without config files.
     *
     * @param mixed                               $driver
     * @param class-string<SearchDriverInterface> $expected
     *
     * @return void
     */
    #[DataProvider('drivers')]
    public function test_configured_driver_resolves_to_its_class(mixed $driver, string $expected): void
    {
        $config = new class (['search.driver' => $driver]) implements ConfigInterface {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }
        };

        $container = new class ($config) implements ContainerInterface {
            /** @var array<string, callable> */
            private array $bindings = [];

            public function __construct(private readonly ConfigInterface $config)
            {
            }

            public function bind(string $abstract, string|callable|null $factory = null): static
            {
                if (is_callable($factory)) {
                    $this->bindings[$abstract] = $factory;
                }

                return $this;
            }

            public function make(string $abstract): mixed
            {
                return $abstract === ConfigInterface::class ? $this->config : ($this->bindings[$abstract])($this);
            }

            public function has(string $abstract): bool
            {
                return isset($this->bindings[$abstract]);
            }

            public function instance(string $abstract, object $instance): void
            {
            }
        };

        (new SearchServiceProvider($container))->register();
        $index = $container->make(SearchIndex::class);

        $this->assertInstanceOf(SearchIndex::class, $index);
        $this->assertInstanceOf($expected, $index->getDriver());
    }
}
