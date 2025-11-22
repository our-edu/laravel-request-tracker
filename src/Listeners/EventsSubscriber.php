<?php

namespace OurEdu\RequestTracker\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use OurEdu\RequestTracker\Jobs\TrackUserAccessJob;

class EventsSubscriber
{
    /**
     * Called after request is fully handled (after middleware including auth).
     * Track the request and update database.
     */
    public function handleRequestHandled(RequestHandled $event)
    {
        try {
            $this->doHandleRequestHandled($event);
        } catch (\Throwable $e) {
            // Silent fail - log error but don't break the application
            $this->logError('handleRequestHandled', $e);
        }
    }
    
    /**
     * Internal request handler with tracking logic
     */
    protected function doHandleRequestHandled(RequestHandled $event)
    {
        $request = $event->request;
        $config  = config('request-tracker', []);

        if (empty($config['enabled'])) {
            logger()->info('[Request Tracker] Tracking is disabled in config');
            return;
        }
        
        logger()->info('[Request Tracker] Starting to track request', [
            'path' => $request->path(),
            'method' => $request->method(),
        ]);
        $method = $request->method();

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
                logger()->info('[Request Tracker] Path excluded by pattern', ['path' => $path, 'pattern' => $pattern]);
                return;
            }
        }

        // Only track authenticated users
        $user = null;
        try {
            // Try configured guards
            $guards = $config['auth_guards'] ?? ['web', 'api'];
            logger()->info('[Request Tracker] Checking authentication guards', ['guards' => $guards]);
            
            foreach ($guards as $guard) {
                $user = auth($guard)->user();
                if ($user) {
                    logger()->info('[Request Tracker] User found via guard', ['guard' => $guard]);
                    break;
                }
            }
            
            // Fallback to request->user()
            if (!$user) {
                $user = $request->user();
            }
        } catch (\Throwable $e) {
            logger()->warning('[Request Tracker] Error checking authentication', ['error' => $e->getMessage()]);
            $user = null;
        }

        if (!$user || is_null($user) || is_null($user->getAuthIdentifier())) {
            logger()->info('[Request Tracker] No authenticated user found', [
                'has_bearer_token' => !empty($request->bearerToken()),
                'has_authorization_header' => $request->hasHeader('Authorization'),
            ]);
            return;
        }

        $userId = $user->getAuthIdentifier();
        logger()->info('[Request Tracker] User authenticated', ['user_uuid' => $userId]);

        // Get bearer token and resolve role from user_sessions table
        $roleUuid = null;
        $userSessionUuid = null;
        $token = $request->bearerToken();
        if ($token) {
            $userSession = DB::table('user_sessions')->where([
                'user_uuid' => $userId,
                'token'     => $token
            ])->first();

            if (!$userSession) {
                logger()->info('[Request Tracker] No user session found for token');
                return; 
            }
            
            if (is_null($userSession->role_id)) {
                logger()->info('[Request Tracker] User session has no role_id', ['session' => $userSession]);
                return; 
            }
            
            $roleUuid = $userSession->role_id;
            $userSessionUuid = $userSession->uuid ?? null;
            
            // Get role name from roles table
            $roleName = null;
            if ($roleUuid) {
                $role = DB::table('roles')
                    ->where('id', $roleUuid)
                    ->first();
                $roleName = $role ? $role->name : null;
            }
            
            logger()->info('[Request Tracker] User session found', [
                'role_uuid' => $roleUuid,
                'role_name' => $roleName,
                'session_uuid' => $userSessionUuid
            ]);
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
        
        // Dispatch tracking job to queue (always async)
        logger()->info('[Request Tracker] Dispatching tracking job to queue', [
            'user_uuid' => $userId,
            'role_name' => $roleName ?? 'N/A',
        ]);
        
        TrackUserAccessJob::dispatch(
            $userId,
            $roleUuid,
            $roleName,
            $userSessionUuid,
            $today,
            $routeName,
            $controllerAction,
            $ipAddress,
            $userAgent,
            $deviceInfo,
            $request->method(),
            $request->path(),
            $config
        );
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

    /**
     * Log errors silently without breaking the application
     */
    protected function logError(string $method, \Throwable $e): void
    {
        $config = config('request-tracker', []);
        $silentErrors = $config['silent_errors'] ?? true;
        
        if ($silentErrors) {
            // Log to Laravel log file
            if (function_exists('logger')) {
                logger()->warning("[Request Tracker] Error in {$method}: " . $e->getMessage(), [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        } else {
            // Re-throw if silent errors disabled
            throw $e;
        }
    }

    public function subscribe($events)
    {
        $events->listen(
            \Illuminate\Foundation\Http\Events\RequestHandled::class,
            [EventsSubscriber::class, 'handleRequestHandled']
        );
    }
}
