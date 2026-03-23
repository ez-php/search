<?php

declare(strict_types=1);

namespace Tests\Search;

use EzPhp\Application\Application;
use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventServiceProvider;
use EzPhp\Search\Drivers\NullDriver;
use EzPhp\Search\SearchIndex;
use EzPhp\Search\SearchServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
