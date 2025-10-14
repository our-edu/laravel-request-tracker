<?php

namespace OurEdu\RequestTracker;

use Illuminate\Support\ServiceProvider;
use OurEdu\RequestTracker\Listeners\EventsSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Routing\Router;

class RequestTrackerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/request-tracker.php', 'request-tracker');
    }

    public function boot(Router $router)
    {
        // publish config & migration
        $this->publishes([
            __DIR__.'/config/request-tracker.php' => config_path('request-tracker.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../database/migrations/2025_01_01_000000_create_request_trackers_table.php' =>
                database_path('migrations/'.date('Y_m_d_His').'_create_request_trackers_table.php'),
        ], 'migrations');

        // register the event subscriber
        Event::subscribe(EventsSubscriber::class);

    }
}
