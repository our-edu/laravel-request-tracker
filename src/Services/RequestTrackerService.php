<?php

namespace OurEdu\RequestTracker\Services;

use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\UserAccessDetail;
use Carbon\Carbon;

class RequestTrackerService
{
    /**
     * Get user's last access time
     * 
     * @param string $userUuid
     * @param string|null $roleUuid Optional: filter by specific role
     * @return \Carbon\Carbon|null
     */
    public function getLastAccess(string $userUuid, ?string $roleUuid = null): ?Carbon
    {
        $query = RequestTracker::where('user_uuid', $userUuid);
        
        if ($roleUuid) {
            $query->where('role_uuid', $roleUuid);
        }
        
        $tracker = $query->orderByDesc('last_access')->first();
        
        return $tracker ? $tracker->last_access : null;
    }

    /**
     * Get user's first access time
     * 
     * @param string $userUuid
     * @param string|null $roleUuid
     * @return \Carbon\Carbon|null
     */
    public function getFirstAccess(string $userUuid, ?string $roleUuid = null): ?Carbon
    {
        $query = RequestTracker::where('user_uuid', $userUuid);
        
        if ($roleUuid) {
            $query->where('role_uuid', $roleUuid);
        }
        
        $tracker = $query->orderBy('first_access')->first();
        
        return $tracker ? $tracker->first_access : null;
    }

    /**
     * Get today's activity summary for a user
     * 
     * @param string $userUuid
     * @param string|null $roleUuid
     * @return array
     */
    public function getTodayActivity(string $userUuid, ?string $roleUuid = null): array
    {
        $query = RequestTracker::where('user_uuid', $userUuid)
            ->where('date', today());
        
        if ($roleUuid) {
            $query->where('role_uuid', $roleUuid);
        }
        
        $tracker = $query->first();
        
        if (!$tracker) {
            return [
                'active' => false,
                'access_count' => 0,
                'first_access' => null,
                'last_access' => null,
                'modules_visited' => [],
            ];
        }
        
        $modules = UserAccessDetail::where('user_uuid', $userUuid)
            ->where('date', today())
            ->when($roleUuid, fn($q) => $q->where('role_uuid', $roleUuid))
            ->groupBy('module')
            ->selectRaw('module, count(distinct endpoint) as endpoint_count, sum(visit_count) as total_visits')
            ->get();
        
        return [
            'active' => true,
            'access_count' => $tracker->access_count,
            'first_access' => $tracker->first_access,
            'last_access' => $tracker->last_access,
            'modules_visited' => $modules->pluck('endpoint_count', 'module')->toArray(),
        ];
    }

    /**
     * Check if user is currently active (accessed within last X minutes)
     * 
     * @param string $userUuid
     * @param int $minutesThreshold Default: 5 minutes
     * @param string|null $roleUuid
     * @return bool
     */
    public function isActive(string $userUuid, int $minutesThreshold = 5, ?string $roleUuid = null): bool
    {
        $lastAccess = $this->getLastAccess($userUuid, $roleUuid);
        
        if (!$lastAccess) {
            return false;
        }
        
        return $lastAccess->diffInMinutes(now()) <= $minutesThreshold;
    }

    /**
     * Get activity summary for a date range
     * 
     * @param string $userUuid
     * @param \Carbon\Carbon $startDate
     * @param \Carbon\Carbon $endDate
     * @param string|null $roleUuid
     * @return array
     */
    public function getActivitySummary(string $userUuid, Carbon $startDate, Carbon $endDate, ?string $roleUuid = null): array
    {
        $query = RequestTracker::where('user_uuid', $userUuid)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
        
        if ($roleUuid) {
            $query->where('role_uuid', $roleUuid);
        }
        
        $records = $query->get();
        
        return [
            'total_days' => $records->count(),
            'total_requests' => $records->sum('access_count'),
            'first_access' => $records->min('first_access'),
            'last_access' => $records->max('last_access'),
            'daily_average' => $records->count() > 0 ? round($records->avg('access_count'), 2) : 0,
        ];
    }

    /**
     * Get all modules accessed by user
     * 
     * @param string $userUuid
     * @param \Carbon\Carbon|null $startDate
     * @param \Carbon\Carbon|null $endDate
     * @param string|null $roleUuid
     * @return \Illuminate\Support\Collection
     */
    public function getModulesAccessed(string $userUuid, ?Carbon $startDate = null, ?Carbon $endDate = null, ?string $roleUuid = null)
    {
        $query = UserAccessDetail::where('user_uuid', $userUuid);
        
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
        }
        
        if ($roleUuid) {
            $query->where('role_uuid', $roleUuid);
        }
        
        return $query->groupBy('module')
            ->selectRaw('module, count(distinct endpoint) as unique_endpoints, sum(visit_count) as total_visits')
            ->orderByDesc('total_visits')
            ->get();
    }

    /**
     * Get users who accessed a specific module
     * 
     * @param string $module
     * @param \Carbon\Carbon $startDate
     * @param \Carbon\Carbon $endDate
     * @param string|null $roleUuid
     * @return \Illuminate\Support\Collection
     */
    public function getUsersWhoAccessedModule(string $module, Carbon $startDate, Carbon $endDate, ?string $roleUuid = null)
    {
        $query = UserAccessDetail::whereModule($module, $startDate, $endDate);
        
        if ($roleUuid) {
            $query->where('role_uuid', $roleUuid);
        }
        
        return $query->get();
    }

    /**
     * Manually track user access (for custom login tracking, etc.)
     * 
     * @param string $userUuid
     * @param string $roleUuid
     * @param string|null $module Optional module name
     * @param string|null $annotation Optional human-readable description
     * @param \Carbon\Carbon|null $timestamp Optional timestamp (defaults to now)
     * @param string|null $ipAddress Optional IP address
     * @param string|null $userAgent Optional user agent string
     * @return \OurEdu\RequestTracker\Models\RequestTracker
     */
    public function trackAccess(
        string $userUuid, 
        string $roleUuid, 
        ?string $module = null, 
        ?string $annotation = null,
        ?Carbon $timestamp = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): RequestTracker {
        $timestamp = $timestamp ?? now();
        $date = $timestamp->format('Y-m-d');
        
        // Find or create daily tracker
        $tracker = RequestTracker::where('user_uuid', $userUuid)
            ->where('role_uuid', $roleUuid)
            ->where('date', $date)
            ->first();
        
        if ($tracker) {
            // Update existing tracker
            $tracker->increment('access_count');
            $tracker->update(['last_access' => $timestamp]);
        } else {
            // Create new tracker
            $tracker = RequestTracker::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'user_uuid' => $userUuid,
                'role_uuid' => $roleUuid,
                'date' => $date,
                'access_count' => 1,
                'first_access' => $timestamp,
                'last_access' => $timestamp,
            ]);
        }
        
        // Track module if provided
        if ($module) {
            $this->trackModule($tracker, $module, $annotation, $timestamp, $date);
        }
        
        return $tracker;
    }

    /**
     * Manually track module access
     * 
     * @param string $userUuid
     * @param string $roleUuid
     * @param string $module
     * @param string|null $submodule
     * @param string|null $annotation
     * @param string|null $endpoint
     * @param \Carbon\Carbon|null $timestamp
     * @return \OurEdu\RequestTracker\Models\UserAccessDetail
     */
    public function trackModuleAccess(
        string $userUuid,
        string $roleUuid,
        string $module,
        ?string $submodule = null,
        ?string $annotation = null,
        ?string $endpoint = null,
        ?Carbon $timestamp = null
    ): UserAccessDetail {
        $timestamp = $timestamp ?? now();
        $date = $timestamp->format('Y-m-d');
        $endpoint = $endpoint ?? "{$module}" . ($submodule ? "/{$submodule}" : "");
        
        // Ensure daily tracker exists
        $tracker = $this->trackAccess($userUuid, $roleUuid, null, null, $timestamp);
        
        // Find or create module access detail
        $detail = UserAccessDetail::where('user_uuid', $userUuid)
            ->where('role_uuid', $roleUuid)
            ->where('endpoint', $endpoint)
            ->where('date', $date)
            ->first();
        
        if ($detail) {
            // Update existing
            $detail->increment('visit_count');
            $detail->update(['last_visit' => $timestamp]);
        } else {
            // Create new
            $detail = UserAccessDetail::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'tracker_uuid' => $tracker->uuid,
                'user_uuid' => $userUuid,
                'role_uuid' => $roleUuid,
                'date' => $date,
                'method' => 'MANUAL',
                'endpoint' => $endpoint,
                'module' => $module,
                'submodule' => $submodule,
                'annotation' => $annotation,
                'visit_count' => 1,
                'first_visit' => $timestamp,
                'last_visit' => $timestamp,
            ]);
        }
        
        return $detail;
    }

    /**
     * Helper: Track module for existing tracker
     */
    protected function trackModule($tracker, $module, $annotation, $timestamp, $date)
    {
        UserAccessDetail::firstOrCreate([
            'user_uuid' => $tracker->user_uuid,
            'role_uuid' => $tracker->role_uuid,
            'endpoint' => $module,
            'date' => $date,
        ], [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tracker_uuid' => $tracker->uuid,
            'method' => 'MANUAL',
            'module' => $module,
            'annotation' => $annotation ?? ucfirst($module),
            'visit_count' => 1,
            'first_visit' => $timestamp,
            'last_visit' => $timestamp,
        ]);
    }
}

