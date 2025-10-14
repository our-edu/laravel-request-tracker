<?php

namespace OurEdu\RequestTracker\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled as LaravelRequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OurEdu\RequestTracker\Models\RequestTracker;

class EventsSubscriber
{
    /**
     * Handle RouteMatched OR Octane RequestReceived.
     * Avoid type-hinting so we can accept different event classes.
     */
    public function handleRouteMatched($event)
    {
        // Try to resolve the request and route from the event (works for RouteMatched and Octane RequestReceived)
        $request = $event->request ?? ($event instanceof RouteMatched ? $event->request : null);
        $route   = $event->route ?? ($event instanceof RouteMatched ? $event->route : null);

        if (! $request) {
            return;
        }

        $config = config('request-tracker', []);
        if (empty($config['enabled'])) {
            return;
        }

        if (! $request->user()) {
            return;
        }

        $user = $request->user();
        $userId = $user->getAuthIdentifier();

        $attributes = [
            'user_uuid'   => $userId,
            'method'      => $request->method(),
            'application' => config('app.name'),
            'path'        => $request->path(),
        ];

        $values = [
            'uuid'        => (string) Str::uuid(),
            'auth_guards' => implode(',', config('request-tracker.auth_guards', [])),
            'last_access' => now(),
            'route_name'  => $route?->getName() ?? null,
        ];

        $tracker = RequestTracker::firstOrCreate($attributes, $values);

        try {
            $request->attributes->set('request_tracker_uuid', $tracker->uuid);
        } catch (\Throwable $e) {
            Log::debug('Could not set request attribute request_tracker_uuid: '.$e->getMessage());
        }
    }

    /**
     * Handle RequestHandled (Laravel) OR Octane RequestHandled.
     * Avoid type-hinting for the same reason as above.
     */
    public function handleRequestHandled($event)
    {
        $request  = $event->request ?? (isset($event->request) ? $event->request : null);
        $response = $event->response ?? (isset($event->response) ? $event->response : null);

        if (! $request) {
            return;
        }

        $uuid = $request->attributes->get('request_tracker_uuid');
        if (! $uuid) {
            return;
        }

        $tracker = RequestTracker::where('uuid', $uuid)->first();
        if (! $tracker) {
            return;
        }

        $token = $tracker->token ?? $this->extractTokenFromRequest($request);

        if (! $token) {
            $tracker->update(['last_access' => now()]);
            return;
        }

        $userSession = DB::table('user_sessions')->where('token', $token)->first();

        if ($userSession) {
            $update = [
                'user_session_uuid' => $userSession->uuid ?? null,
                'role_uuid'         => $userSession->role_uuid ?? null,
                'last_access'       => now(),
            ];

            if (empty($tracker->token)) {
                $update['token'] = $token;
            }

            $tracker->update($update);
        } else {
            $tracker->update(['last_access' => now()]);
        }

        // optionally capture status code
        if ($response && method_exists($response, 'getStatusCode')) {
            try {
                $tracker->status_code = $response->getStatusCode();
                $tracker->save();
            } catch (\Throwable $e) {
                Log::debug('Failed to save tracker response: '.$e->getMessage());
            }
        }
    }

    /**
     * Subscribe events to handlers
     */
    public function subscribe($events)
    {
        // Normal Laravel lifecycle
        $events->listen(
            \Illuminate\Routing\Events\RouteMatched::class,
            [self::class, 'handleRouteMatched']
        );

        $events->listen(
            \Illuminate\Foundation\Http\Events\RequestHandled::class,
            [self::class, 'handleRequestHandled']
        );

        // Octane events: map RequestReceived -> handleRouteMatched, RequestHandled -> handleRequestHandled
        if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            $events->listen(
                \Laravel\Octane\Events\RequestReceived::class,
                [self::class, 'handleRouteMatched']
            );
        }

        if (class_exists(\Laravel\Octane\Events\RequestHandled::class)) {
            $events->listen(
                \Laravel\Octane\Events\RequestHandled::class,
                [self::class, 'handleRequestHandled']
            );
        }
    }

    protected function extractTokenFromRequest($request): ?string
    {
        if (method_exists($request, 'bearerToken') && $request->bearerToken()) {
            return $request->bearerToken();
        }

        $headerToken = $request->header('x-access-token') ?? $request->header('x-api-key');
        if ($headerToken) return $headerToken;

        $inputToken = $request->input('token') ?? $request->query('token');
        if ($inputToken) return $inputToken;

        if ($request->cookies->has('token')) return $request->cookies->get('token');

        return null;
    }
}
