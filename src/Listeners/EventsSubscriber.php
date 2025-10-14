<?php

namespace OurEdu\RequestTracker\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use OurEdu\RequestTracker\Models\RequestTracker;
use Illuminate\Support\Facades\DB;
use Throwable;

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

        // Normalize and lower-case path (no leading/trailing slash)
        $path   = trim($request->path(), '/');
        $path   = strtolower($path);
        $method = $request->method();
        // Use config helper (safe for Octane / long-lived processes)
        $app    = config('app.name');

        // check exclude patterns
        $excludes = $config['exclude'] ?? [];
        foreach ($excludes as $pattern) {
            $pattern = trim($pattern, '/');
            $pattern = strtolower($pattern);

            if (\Illuminate\Support\Str::startsWith($pattern, 'regex:')) {
                $regex = substr($pattern, 6);
                if (@preg_match($regex, $path)) {
                    return;
                }
                continue;
            }

            if (strpos($pattern, '*') !== false) {
                if (@fnmatch($pattern, $path)) {
                    return;
                }
                continue;
            }

            if (\Illuminate\Support\Str::endsWith($path, $pattern)) {
                return;
            }
        }

        // Prefer authenticated user
        $user = null;
        try {
            $user = $request->user();
        } catch (Throwable $e) {
            $user = null;
        }

        $tracker = null;
        $shouldSetGuestCookie = false;

        if ($user) {
            $userId = $user->getAuthIdentifier();

            // Use safe DB wrapper to handle stale connections on Octane.
            $tracker = $this->safeDb(function () use ($userId, $path, $method, $app, $config) {
                // Use updateOrCreate to ensure we update last_access when a record already exists
                return RequestTracker::updateOrCreate(
                    ['user_uuid' => $userId, 'path' => $path],
                    [
                        'uuid'             => (string) Str::uuid(),
                        'method'           => $method,
                        'application'      => $app,
                        'auth_guards'      => implode(',', $config['auth_guards'] ?? []),
                        'last_access'      => now(),
                    ]
                );
            });
        } else {
            // Guest flow: try cookie with tracker uuid
            $cookieUuid = $request->cookie('request_tracker_uuid');

            if ($cookieUuid) {
                $tracker = $this->safeDb(function () use ($cookieUuid) {
                    return RequestTracker::where('uuid', $cookieUuid)->first();
                });

                // If tracker exists but path differs, force new create
                if ($tracker && $tracker->path !== $path) {
                    $tracker = null;
                }
            }

            if (! $tracker) {
                // Create a new tracker for this guest+path (transaction to reduce race issues)
                $tracker = $this->safeDb(function () use ($path, $method, $app, $config) {
                    return DB::transaction(function () use ($path, $method, $app, $config) {
                        return RequestTracker::create([
                            'uuid'        => (string) Str::uuid(),
                            'method'      => $method,
                            'application' => $app,
                            'auth_guards' => implode(',', $config['auth_guards'] ?? []),
                            'path'        => $path,
                            'last_access' => now(),
                        ]);
                    });
                });

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
        if (! $uuid) {
            return;
        }

        $tracker = $this->safeDb(function () use ($uuid) {
            return RequestTracker::where('uuid', $uuid)->first();
        });

        if (! $tracker) {
            return;
        }

        // Resolve user id (update if needed)
        $userId = null;
        try {
            if ($user = $request->user()) {
                $userId = $user->getAuthIdentifier();
            } else {
                foreach (config('request-tracker.auth_guards', []) as $guard) {
                    if ($u = Auth::guard($guard)->user()) {
                        $userId = $u->getAuthIdentifier();
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $userId = null;
        }

        $token = $request->bearerToken();
        $userSession = null;
        if ($token) {
            $userSession = $this->safeDb(function () use ($userId, $token) {
                return DB::table('user_sessions')->where([
                    'user_uuid' => $userId,
                    'token'     => $token
                ])->first();
            });
        }

        // Refresh tracker model to avoid stale data in long-lived worker
        try {
            $tracker->refresh();
        } catch (Throwable $_) {
            // ignore refresh errors and continue with best-effort update
        }

        // Update last_access and other fields (safe write)
        $this->safeDb(function () use ($tracker, $userId, $request, $userSession) {
            $tracker->update([
                'user_uuid'          => $userId,
                'last_access'        => now(),
                'path'               => trim($request->path(), '/'),
                'method'             => $request->method(),
                'application'        => config('app.name'),
                'user_session_uuid'  => $userSession ? $userSession->uuid : null,
                'role_uuid'          => $userSession ? $userSession->role_id : null,
            ]);
        });

        // If we flagged that a guest cookie is required, attach it to response
        if ($request->attributes->get('request_tracker_set_cookie')) {
            $cookie = cookie()->forever('request_tracker_uuid', $tracker->uuid);
            if ($response) {
                $response->headers->setCookie($cookie);
            }
        }
    }

    /**
     * Safe DB wrapper for Octane: try once, on failure reconnect and retry a single time.
     * Keep this small and idempotent (avoid side-effects on retry).
     *
     * IMPORTANT: The callable should be idempotent or safe to run twice (selects, updateOrCreate, etc.).
     */
    protected function safeDb(callable $work)
    {
        try {
            return $work();
        } catch (Throwable $e) {
            // Typical Octane scenario: DB connection timed out. Try reconnect once.
            try {
                DB::reconnect();
            } catch (Throwable $_) {
                // ignore
            }

            // Try again once
            return $work();
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
