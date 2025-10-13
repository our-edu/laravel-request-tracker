<?php

namespace Ouredu\UserLastAccess\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
         //   LastAccessServiceProvider::class,
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
