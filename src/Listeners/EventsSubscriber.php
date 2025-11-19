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

        // Only track authenticated users
        $user = null;
        try {
            $user = $request->user();
        } catch (\Throwable $e) {
            $user = null;
        }

        if (!$user || is_null($user) || is_null($user->getAuthIdentifier())) {
            return;
        }

        $userId = $user->getAuthIdentifier();

        // Get bearer token and resolve role from user_sessions table
        $roleUuid = null;
        $token = $request->bearerToken();
        if ($token) {
            $userSession = DB::table('user_sessions')->where([
                'user_uuid' => $userId,
                'token'     => $token
            ])->first();

            if (!$userSession || is_null($userSession->role_id)) {
                return; 
            }
            $roleUuid = $userSession->role_id;
        }

        // Get today's date for unique daily tracking
        $today = now()->format('Y-m-d');

        // Check if a record already exists for this user + role + date
        $existingTracker = RequestTracker::where('user_uuid', $userId)
            ->where('role_uuid', $roleUuid)
            ->where('date', $today)
            ->first();

        // If record exists for today, skip creating a new one
        if ($existingTracker) {
            $existingTracker->update([
                'last_access' => now(),
            ]);
            $tracker = $existingTracker;
        } else {
            // Create new tracker for this user + role + date
            $tracker = RequestTracker::create([
                'uuid'        => (string) Str::uuid(),
                'user_uuid'   => $userId,
                'role_uuid'   => $roleUuid,
                'method'      => $method,
                'application' => $app,
                'auth_guards' => implode(',', $config['auth_guards'] ?? []),
                'path'        => $path,
                'date'        => $today,
                'last_access' => now(),
            ]);
        }

        // Attach tracker uuid to request
        if ($tracker) {
            $request->attributes->set('request_tracker_uuid', $tracker->uuid);
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

        // Update last_access timestamp
        $tracker->update([
            'last_access' => now(),
        ]);
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
