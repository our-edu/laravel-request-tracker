<?php

namespace Ouredu\UserLastAccess;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Ouredu\UserLastAccess\Listeners\UserLastAccessListener;

class LastAccessServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Publish and load migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Listen for the end of every request
        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            \Log::error('RequestHandled event FIRED');
            \Log::error('Request path: ' . $event->request->path());
            if ($event->request->is('api/*')) {
                \Log::error('Calling UserLastAccessListener from ServiceProvider');
                (new UserLastAccessListener())->handle($event->request);
            }
        });

    }
}