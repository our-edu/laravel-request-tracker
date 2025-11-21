<?php

namespace OurEdu\RequestTracker;

use Couchbase\RequestTracer;
use Illuminate\Support\ServiceProvider;
use OurEdu\RequestTracker\Listeners\EventsSubscriber;
use OurEdu\RequestTracker\Services\RequestTrackerService;
use Illuminate\Support\Facades\Event;
use Illuminate\Routing\Router;

class RequestTrackerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/request-tracker.php', 'request-tracker');
        
        // Register the service as singleton
        $this->app->singleton('request-tracker', function ($app) {
            return new RequestTrackerService();
        });
    }

    public function boot(Router $router)
    {
        // publish config & migrations
        $this->publishes([
            __DIR__.'/config/request-tracker.php' => config_path('request-tracker.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../database/migrations/2025_01_01_000000_create_request_trackers_table.php' =>
                database_path('migrations/'.date('Y_m_d_His', time()).'_create_request_trackers_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000001_create_access_logs_table.php' =>
                database_path('migrations/'.date('Y_m_d_His', time() + 2).'_create_user_access_details_table.php'),
        ], 'migrations');

        // register the event subscriber
        Event::subscribe(EventsSubscriber::class);
        
        // register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \OurEdu\RequestTracker\Console\Commands\UserAccessStatsCommand::class,
                \OurEdu\RequestTracker\Console\Commands\UserJourneyCommand::class,
                \OurEdu\RequestTracker\Console\Commands\CleanupLogsCommand::class,
                \OurEdu\RequestTracker\Console\Commands\ModuleAccessCommand::class,
            ]);
        }
    }
}
