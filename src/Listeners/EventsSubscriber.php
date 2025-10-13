<?php

namespace OurEdu\RequestTracker\Listeners;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use OurEdu\RequestTracker\Models\RequestTracker;

class EventsSubscriber
{
    // When route matched (early point) -> create initial tracker
    public function handleRouteMatched(RouteMatched $event)
    {
        $request = $event->request;
        $config = config('request-tracker');

        if (!$config['enabled']) return;



        $uuid = (string) Str::uuid();

        $payload = $request->all();


        $tracker = RequestTracker::create([
            'uuid' => $uuid,
            'method' => $request->method(),
            'application' => env('APP_NAME') ,
            'auth_guards' => implode(',', $config['auth_guards'] ?? []),
        ]);

        $request->attributes->set('request_tracker_uuid', $uuid);
    }

    // When request handled (after response) -> update tracker
    public function handleRequestHandled(RequestHandled $event)
    {
        $request = $event->request;
        $response = $event->response;

        $uuid = $request->attributes->get('request_tracker_uuid');

        if (!$uuid) {
            // maybe create one now if you want to track requests that skipped RouteMatched
            return;
        }

        $tracker = RequestTracker::where('uuid', $uuid)->first();
        if (!$tracker) return;

        // resolve user id if available
        $userId = null;
        try {
            $user = $request->user();
            if ($user) $userId = $user->getAuthIdentifier();
            else {
                // fallback to Auth facade guards defined in config
                foreach (config('request-tracker.auth_guards', []) as $g) {
                    if ($u = Auth::guard($g)->user()) {
                        $userId = $u->getAuthIdentifier();
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $userId = null;
        }
        $tracker->update([
            'user_uuid' => $userId,


        ]);
    }

    // Subscription: wire events to handlers
    public function subscribe($events)
    {
        $events->listen(
            \Illuminate\Routing\Events\RouteMatched::class,
            [EventsSubscriber::class, 'handleRouteMatched']
        );

        $events->listen(
            \Illuminate\Foundation\Http\Events\RequestHandled::class,
            [EventsSubscriber::class, 'handleRequestHandled']
        );

    }
}
