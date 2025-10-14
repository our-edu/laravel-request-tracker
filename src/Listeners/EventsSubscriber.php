<?php

namespace OurEdu\RequestTracker\Listeners;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OurEdu\RequestTracker\Models\RequestTracker;

class EventsSubscriber
{
    // When route matched (early point) -> create initial tracker
    public function handleRouteMatched(RouteMatched $event)
    {
        $request = $event->request;
        $config = config('request-tracker', []);

        if (empty($config['enabled'])) {
            return;
        }

        // only track authenticated users (if that's your rule)
        if (! $request->user()) {
            return;
        }

        $user = $request->user();
        $userId = $user->getAuthIdentifier();

        // prevent duplicated where clauses -- use meaningful unique attributes
        $attributes = [
            'user_uuid' => $userId,
            'method'    => $request->method(),
            'application'=> config('app.name'),
            'path'      => $request->path(),
            // you can include route name for more specificity
        ];

        $values = [
            'uuid' => (string) Str::uuid(),
            'auth_guards' => implode(',', config('request-tracker.auth_guards', [])),
            'last_access' => now(),
            'route_name' => $event->route?->getName() ?? null,
        ];

        // firstOrCreate will return existing tracker if attributes match
        $tracker = RequestTracker::firstOrCreate($attributes, $values);

        // expose tracker uuid on the request so later events can read it
        $request->attributes->set('request_tracker_uuid', $tracker->uuid);
    }

    // When request handled (after response) -> update tracker
    public function handleRequestHandled(RequestHandled $event)
    {
        $request = $event->request;
        $uuid = $request->attributes->get('request_tracker_uuid');

        if (! $uuid) {
            // optional: create a new tracker here if you want to capture anonymous requests
            return;
        }

        $tracker = RequestTracker::where('uuid', $uuid)->first();
        if (! $tracker) {
            return;
        }

        // Resolve token: prefer tracker token (if you already saved it), otherwise check request
        $token = $tracker->token ?? $this->extractTokenFromRequest($request);

        if (! $token) {
            // nothing to link to session
            $tracker->update([
                'last_access' => now(),
            ]);
            return;
        }

        // fetch user session by token
        $userSession = DB::table('user_sessions')->where('token', $token)->first();

        // if session found -> update tracker with session info
        if ($userSession) {
            // make sure we use correct fields and null-safety (some columns may not exist)
            $update = [
                'user_session_uuid' => $userSession->uuid ?? null,
                'role_uuid'         => $userSession->role_uuid ?? null,
                'last_access'       => now(),
            ];

            // optionally update token if not present on tracker
            if (empty($tracker->token)) {
                $update['token'] = $token;
            }

            $tracker->update($update);
        } else {
            // session not found: still update last_access and optionally mark something
            $tracker->update([
                'last_access' => now(),
                // 'session_missing' => true, // optionally set a flag column
            ]);
        }
    }

    /**
     * Subscribe events to handlers
     * (this is used by EventServiceProvider->subscribe([...]))
     */
    public function subscribe($events)
    {
        $events->listen(
            \Illuminate\Routing\Events\RouteMatched::class,
            [self::class, 'handleRouteMatched']
        );

        $events->listen(
            \Illuminate\Foundation\Http\Events\RequestHandled::class,
            [self::class, 'handleRequestHandled']
        );

        // Octane events (conditionally register so package works without Octane)

        if (class_exists(\Laravel\Octane\Events\RequestHandled::class)) {
            $events->listen(
                \Laravel\Octane\Events\RequestReceived::class,
                [self::class, 'handleRouteMatched'] // event contains ->request
            );
            $events->listen(
                \Laravel\Octane\Events\RequestHandled::class,
                [self::class, 'handleRequestHandled']
            );
        }
    }

    /**
     * Extract a token from the request using common locations:
     * - Authorization Bearer header
     * - request input 'token'
     * - cookie 'token'
     */
    protected function extractTokenFromRequest($request): ?string
    {
        // bearer token header
        $bearer = $request->bearerToken();
        if ($bearer) {
            return $bearer;
        }

        // common header 'x-access-token' or 'x-api-key'
        $headerToken = $request->header('x-access-token') ?? $request->header('x-api-key');
        if ($headerToken) {
            return $headerToken;
        }

        // input / query / body token
        $inputToken = $request->input('token') ?? $request->query('token');
        if ($inputToken) {
            return $inputToken;
        }

        // cookie
        if ($request->cookies->has('token')) {
            return $request->cookies->get('token');
        }

        return null;
    }
}
