<?php

namespace OurEdu\RequestTracker\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use OurEdu\RequestTracker\RequestTrackerServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            RequestTrackerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // Custom config if needed
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Run package migrations
        $this->loadMigrationsFrom(__DIR__.'/../src/database/migrations');
    }
}
