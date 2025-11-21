<?php

namespace OurEdu\RequestTracker\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\UserAccessDetail;
use OurEdu\RequestTracker\Services\ModuleExtractor;

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
        $userSessionUuid = null;
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
            $userSessionUuid = $userSession->uuid ?? null;
        }

        // Get today's date for unique daily tracking
        $today = now()->format('Y-m-d');
        
        // Get route details
        $route = $request->route();
        $routeName = $route ? $route->getName() : null;
        $controllerAction = null;
        if ($route && $route->getActionName() !== 'Closure') {
            $controllerAction = $route->getActionName();
        }
        
        // Get device and network info
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();
        $deviceInfo = $this->parseUserAgent($userAgent);

        // Check if a record already exists for this user + role + date
        $existingTracker = RequestTracker::where('user_uuid', $userId)
            ->where('role_uuid', $roleUuid)
            ->where('date', $today)
            ->first();

        // If record exists for today, increment access count
        if ($existingTracker) {
            $existingTracker->increment('access_count');
            $existingTracker->update([
                'last_access' => now(),
            ]);
            $tracker = $existingTracker;
        } else {
            // Create new tracker for this user + role + date
            $tracker = RequestTracker::create([
                'uuid'              => (string) Str::uuid(),
                'user_uuid'         => $userId,
                'role_uuid'         => $roleUuid,
                'user_session_uuid' => $userSessionUuid,
                'application'       => $app,
                'date'              => $today,
                'access_count'      => 1,
                'first_access'      => now(),
                'last_access'       => now(),
                'ip_address'        => $ipAddress,
                'user_agent'        => $userAgent,
                'device_type'       => $deviceInfo['device_type'],
                'browser'           => $deviceInfo['browser'],
                'platform'          => $deviceInfo['platform'],
            ]);
        }

        // Track the specific endpoint visited
        $this->trackEndpointVisit($tracker, $request, $routeName, $controllerAction, $today, $config);

        // Attach tracker uuid to request
        if ($tracker) {
            $request->attributes->set('request_tracker_uuid', $tracker->uuid);
            $request->attributes->set('request_user_uuid', $userId);
            $request->attributes->set('request_role_uuid', $roleUuid);
        }
    }

    /**
     * Track the specific endpoint/module visited
     */
    protected function trackEndpointVisit($tracker, $request, $routeName, $controllerAction, $today, $config)
    {
        $endpoint = $request->path();
        
        // Extract module, submodule, and annotation
        $extracted = ModuleExtractor::extract($endpoint, $routeName, $controllerAction, $config);
        
        // Check if this endpoint was already visited today by this user+role
        $existingDetail = UserAccessDetail::where('user_uuid', $tracker->user_uuid)
            ->where('role_uuid', $tracker->role_uuid)
            ->where('endpoint', $endpoint)
            ->where('date', $today)
            ->first();

        if ($existingDetail) {
            // Increment visit count for this endpoint
            $existingDetail->increment('visit_count');
            $existingDetail->update([
                'last_visit' => now(),
            ]);
        } else {
            // Create new endpoint visit record
            UserAccessDetail::create([
                'uuid'              => (string) Str::uuid(),
                'tracker_uuid'      => $tracker->uuid,
                'user_uuid'         => $tracker->user_uuid,
                'role_uuid'         => $tracker->role_uuid,
                'date'              => $today,
                'method'            => $request->method(),
                'endpoint'          => $endpoint,
                'route_name'        => $routeName,
                'controller_action' => $controllerAction,
                'module'            => $extracted['module'],
                'submodule'         => $extracted['submodule'],
                'annotation'        => $extracted['annotation'],
                'visit_count'       => 1,
                'first_visit'       => now(),
                'last_visit'        => now(),
            ]);
        }
    }

    /**
     * Called after the response. Update last access time.
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

        // Update last access
        $tracker->update([
            'last_access' => now(),
        ]);
    }

    /**
     * Parse user agent to extract device info
     */
    protected function parseUserAgent(?string $userAgent): array
    {
        if (!$userAgent) {
            return [
                'device_type' => 'unknown',
                'browser' => 'unknown',
                'platform' => 'unknown',
            ];
        }
        
        $userAgent = strtolower($userAgent);
        
        // Detect device type
        $deviceType = 'desktop';
        if (str_contains($userAgent, 'mobile')) {
            $deviceType = 'mobile';
        } elseif (str_contains($userAgent, 'tablet') || str_contains($userAgent, 'ipad')) {
            $deviceType = 'tablet';
        } elseif (str_contains($userAgent, 'bot') || str_contains($userAgent, 'crawler') || str_contains($userAgent, 'spider')) {
            $deviceType = 'bot';
        }
        
        // Detect browser
        $browser = 'unknown';
        if (str_contains($userAgent, 'edg')) {
            $browser = 'Edge';
        } elseif (str_contains($userAgent, 'chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'safari') && !str_contains($userAgent, 'chrome')) {
            $browser = 'Safari';
        } elseif (str_contains($userAgent, 'firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'opera') || str_contains($userAgent, 'opr')) {
            $browser = 'Opera';
        } elseif (str_contains($userAgent, 'msie') || str_contains($userAgent, 'trident')) {
            $browser = 'Internet Explorer';
        }
        
        // Detect platform
        $platform = 'unknown';
        if (str_contains($userAgent, 'windows')) {
            $platform = 'Windows';
        } elseif (str_contains($userAgent, 'mac') || str_contains($userAgent, 'darwin')) {
            $platform = 'macOS';
        } elseif (str_contains($userAgent, 'iphone') || str_contains($userAgent, 'ipad')) {
            $platform = 'iOS';
        } elseif (str_contains($userAgent, 'android')) {
            $platform = 'Android';
        } elseif (str_contains($userAgent, 'linux')) {
            $platform = 'Linux';
        }
        
        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'platform' => $platform,
        ];
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
