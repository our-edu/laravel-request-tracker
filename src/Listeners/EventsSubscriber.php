<?php

namespace OurEdu\RequestTracker\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use OurEdu\RequestTracker\Models\RequestTracker;
use Illuminate\Support\Facades\DB;

class EventsSubscriber
{
    /**
     * Called when a route is matched. Find or create the single tracker
     * for this (user|guest) + path combination and attach uuid to the request.
     */
    public function handleRouteMatched(RouteMatched $event)
    {
        $request = $event->request;
        $config  = config('request-tracker', []);

        if (empty($config['enabled'])) {
            return;
        }
        $method = $request->method();
        $app    = env('APP_NAME');

        // normalize current path (no leading/trailing slash, lowercase for safe matching)
        $path = trim($request->path(), '/');           // e.g. "admission/api/v1/ar/parent/look-up"
        $path = strtolower($path);                     // make matching case-insensitive

        // check exclude patterns
        $excludes = $config['exclude'] ?? [];
        foreach ($excludes as $pattern) {
            $pattern = trim($pattern, '/');
            $pattern = strtolower($pattern);

            // 1) regex pattern: prefix with "regex:"
            if (Str::startsWith($pattern, 'regex:')) {
                $regex = substr($pattern, 6);
                if (@preg_match($regex, $path)) {
                    // matched -> skip tracking
                    return;
                }
                continue;
            }

            // 2) wildcard pattern: contains '*'
            if (strpos($pattern, '*') !== false) {
                // fnmatch expects pattern in shell wildcard format
                // ensure we match the whole path
                if (fnmatch($pattern, $path)) {
                    return;
                }
                continue;
            }

            // 3) default: treat as suffix (what you want for v1/ar/parent/look-up)
            if (Str::endsWith($path, $pattern)) {
                return;
            }
        }

        // Prefer authenticated user
        $user = null;
        try {
            $user = $request->user();
        } catch (\Throwable $e) {
            $user = null;
        }

        $tracker = null;
        $shouldSetGuestCookie = true;

        if ($user) {
            $userId = $user->getAuthIdentifier();

            // Find existing tracker for this user + path OR create one
            // Make sure there's an index/unique constraint for performance (see migration notes)
            $tracker = RequestTracker::firstOrCreate(
                ['user_uuid' => $userId, 'application' => $app],
                [
                    'uuid'        => (string) Str::uuid(),
                    'method'      => $method,
                    'application' => $app,
                    'auth_guards' => implode(',', $config['auth_guards'] ?? []),
                    'last_access' => now(),
                ]
            );
        } else {
            // Guest flow: try cookie with tracker uuid
            $cookieUuid = $request->cookie('request_tracker_uuid');

            if ($cookieUuid) {
                $tracker = RequestTracker::where('uuid', $cookieUuid)->first();

                // If tracker exists but path differs, create a new tracker for this path
                if ($tracker && $tracker->application != $app ) {
                    $tracker = null; // force create new below
                }
            }

            if (!$tracker) {
                // Create a new tracker for this guest+path
                $tracker = RequestTracker::create([
                    'uuid'        => (string) Str::uuid(),
                    'method'      => $method,
                    'application' => $app,
                    'auth_guards' => implode(',', $config['auth_guards'] ?? []),
                    'path'        => $path,
                    'last_access' => now(),
                    // user_uuid stays null for guests
                ]);

                // mark that we must set cookie on response
                $shouldSetGuestCookie = true;
            }
        }

        // Attach tracker uuid + a flag whether we need to set cookie (for guest)
        if ($tracker) {
            $request->attributes->set('request_tracker_uuid', $tracker->uuid);
            if ($shouldSetGuestCookie) {
                $request->attributes->set('request_tracker_set_cookie', true);
            }
        }
    }

    /**
     * Called after the response. Update last_access and optionally set cookie for guests.
     */
    public function handleRequestHandled(RequestHandled $event)
    {
        $request  = $event->request;
        $response = $event->response;

        $uuid = $request->attributes->get('request_tracker_uuid');
        if (!$uuid) {
            return;
        }

        $tracker = RequestTracker::where('uuid', $uuid)->first();
        if (!$tracker) {
            return;
        }

        // Resolve user id (update if needed)
        $userId = null;
        try {
            if ($user = $request->user()) {
                $userId = $user->getAuthIdentifier();
            } else {
                // look through configured auth guards just in case
                foreach (config('request-tracker.auth_guards', []) as $guard) {
                    if ($u = Auth::guard($guard)->user()) {
                        $userId = $u->getAuthIdentifier();
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $userId = null;
        }
        $token = $request->bearerToken();
        if ($token) {
            $userSession = DB::table('user_sessions')->where([
                'user_uuid' => $userId,
                'token'     => $token
            ])->first();

        }

        // Update last_access and other fields
        $tracker->update([
            'user_uuid'   => $userId,
            'last_access' => now(),
            'path'        => $request->path(),
            'method'      => $request->method(),
            'application' => env('APP_NAME'),
            'user_session_uuid' => $userSession ? $userSession->uuid : null,
            'role_uuid'  => $userSession ? $userSession->role_id : null,
        ]);

        // If we flagged that a guest cookie is required, attach it to response
        if ($request->attributes->get('request_tracker_set_cookie')) {
            // set a long-lived, httpOnly cookie that is available to future requests
            // adjust domain/path/secure flags to your needs
            $cookie = cookie()->forever('request_tracker_uuid', $tracker->uuid);
            if ($response) {
                $response->headers->setCookie($cookie);
            }
        }
    }

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
