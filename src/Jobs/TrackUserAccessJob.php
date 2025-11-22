<?php

namespace OurEdu\RequestTracker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\UserAccessDetail;

class TrackUserAccessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;

    protected $userId;
    protected $roleUuid;
    protected $roleName;
    protected $userSessionUuid;
    protected $today;
    protected $routeName;
    protected $controllerAction;
    protected $ipAddress;
    protected $userAgent;
    protected $deviceInfo;
    protected $requestMethod;
    protected $requestPath;
    protected $config;

    public function __construct(
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
        $requestMethod,
        $requestPath,
        $config
    ) {
        $this->userId = $userId;
        $this->roleUuid = $roleUuid;
        $this->roleName = $roleName;
        $this->userSessionUuid = $userSessionUuid;
        $this->today = $today;
        $this->routeName = $routeName;
        $this->controllerAction = $controllerAction;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->deviceInfo = $deviceInfo;
        $this->requestMethod = $requestMethod;
        $this->requestPath = $requestPath;
        $this->config = $config;
    }

    public function handle()
    {
        try {
            logger()->info('[Request Tracker Job] Processing tracking data', [
                'user_uuid' => $this->userId,
                'role_name' => $this->roleName,
                'date' => $this->today,
            ]);

            // Check if a record already exists for this user + role + date
            $existingTracker = RequestTracker::where('user_uuid', $this->userId)
                ->where('role_uuid', $this->roleUuid)
                ->where('date', $this->today)
                ->first();

            // If record exists for today, increment access count
            if ($existingTracker) {
                $existingTracker->increment('access_count');
                $existingTracker->update([
                    'last_access' => now(),
                ]);
                $tracker = $existingTracker;
                
                logger()->info('[Request Tracker Job] Updated existing tracker', [
                    'tracker_uuid' => $tracker->uuid,
                    'access_count' => $tracker->access_count,
                ]);
            } else {
                // Create new tracker for this user + role + date
                logger()->info('[Request Tracker Job] Creating new tracker record', [
                    'user_uuid' => $this->userId,
                    'role_uuid' => $this->roleUuid,
                    'role_name' => $this->roleName,
                    'date' => $this->today,
                ]);
                
                $tracker = RequestTracker::create([
                    'uuid'              => (string) Str::uuid(),
                    'user_uuid'         => $this->userId,
                    'role_uuid'         => $this->roleUuid,
                    'role_name'         => $this->roleName,
                    'user_session_uuid' => $this->userSessionUuid,
                    'date'              => $this->today,
                    'access_count'      => 1,
                    'first_access'      => now(),
                    'last_access'       => now(),
                    'ip_address'        => $this->ipAddress,
                    'user_agent'        => $this->userAgent,
                    'device_type'       => $this->deviceInfo['device_type'],
                    'browser'           => $this->deviceInfo['browser'],
                    'platform'          => $this->deviceInfo['platform'],
                ]);
                
                logger()->info('[Request Tracker Job] New tracker created successfully', [
                    'tracker_uuid' => $tracker->uuid
                ]);
            }

            // Extract and track endpoint details from controller attribute
            $this->trackEndpointDetails($tracker);

        } catch (\Throwable $e) {
            logger()->error('[Request Tracker Job] Error processing tracking data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw to allow job retry mechanism
            throw $e;
        }
    }

    protected function trackEndpointDetails($tracker)
    {
        // Extract tracking data from controller attribute
        $trackingData = $this->extractTrackingData();
        
        if (!$trackingData) {
            logger()->info('[Request Tracker Job] No tracking data extracted', [
                'controller_action' => $this->controllerAction,
                'route_name' => $this->routeName,
                'path' => $this->requestPath,
            ]);
            return; // No tracking attribute found
        }
        
        // Create access detail record
        UserAccessDetail::create([
            'uuid'              => (string) Str::uuid(),
            'tracker_uuid'      => $tracker->uuid,
            'user_uuid'         => $tracker->user_uuid,
            'role_uuid'         => $tracker->role_uuid,
            'role_name'         => $tracker->role_name,
            'date'              => $this->today,
            'method'            => $this->requestMethod,
            'endpoint'          => $this->requestPath,
            'route_name'        => $this->routeName,
            'controller_action' => $this->controllerAction,
            'module'            => $trackingData['module'],
            'submodule'         => $trackingData['submodule'],
            'action'            => $trackingData['action'],
            'visit_count'       => 1,
            'first_visit'       => now(),
            'last_visit'        => now(),
        ]);
        
        logger()->info('[Request Tracker Job] Endpoint details tracked', [
            'module' => $trackingData['module'],
            'submodule' => $trackingData['submodule'],
            'action' => $trackingData['action'],
        ]);
    }

    /**
     * Extract tracking data from controller #[TrackModule] attribute
     */
    protected function extractTrackingData(): ?array
    {
        logger()->info('[Request Tracker Job] Attempting to extract tracking data', [
            'controller_action' => $this->controllerAction,
            'route_name' => $this->routeName,
        ]);
        
        if (!$this->controllerAction) {
            logger()->info('[Request Tracker Job] No controller action found');
            return null;
        }

        // Handle different controller action formats
        $controller = null;
        $method = null;
        
        if (str_contains($this->controllerAction, '@')) {
            // Format: App\Http\Controllers\SubjectApiController@index
            [$controller, $method] = explode('@', $this->controllerAction);
        } else {
            // Format might be just the controller class name
            $controller = $this->controllerAction;
            $method = $this->routeName;
        }
        
        if (!$controller || !class_exists($controller)) {
            logger()->info('[Request Tracker Job] Controller class not found', ['controller' => $controller]);
            return null;
        }

        try {
            $reflectionClass = new \ReflectionClass($controller);
            
            // Check for #[TrackModule] at class level
            $trackModuleAttributes = $reflectionClass->getAttributes(\OurEdu\RequestTracker\Attributes\TrackModule::class);
            if (!empty($trackModuleAttributes)) {
                $moduleAttr = $trackModuleAttributes[0]->newInstance();
                
                $trackingData = [
                    'module' => $moduleAttr->module,
                    'submodule' => $moduleAttr->submodule,
                    'action' => $this->routeName ?? $method ?? 'unknown',
                ];
                
                logger()->info('[Request Tracker Job] TrackModule attribute found', $trackingData);
                
                return $trackingData;
            } else {
                logger()->info('[Request Tracker Job] No TrackModule attribute found on controller', [
                    'controller' => $controller,
                ]);
            }
            
        } catch (\ReflectionException $e) {
            logger()->warning('[Request Tracker Job] Reflection error', ['error' => $e->getMessage()]);
        }
        
        return null;
    }
}
