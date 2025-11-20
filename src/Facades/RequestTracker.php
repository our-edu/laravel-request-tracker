<?php

namespace OurEdu\RequestTracker\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Carbon\Carbon|null getLastAccess(string $userUuid, ?string $roleUuid = null)
 * @method static \Carbon\Carbon|null getFirstAccess(string $userUuid, ?string $roleUuid = null)
 * @method static array getTodayActivity(string $userUuid, ?string $roleUuid = null)
 * @method static bool isActive(string $userUuid, int $minutesThreshold = 5, ?string $roleUuid = null)
 * @method static array getActivitySummary(string $userUuid, \Carbon\Carbon $startDate, \Carbon\Carbon $endDate, ?string $roleUuid = null)
 * @method static \Illuminate\Support\Collection getModulesAccessed(string $userUuid, ?\Carbon\Carbon $startDate = null, ?\Carbon\Carbon $endDate = null, ?string $roleUuid = null)
 * @method static \Illuminate\Support\Collection getUsersWhoAccessedModule(string $module, \Carbon\Carbon $startDate, \Carbon\Carbon $endDate, ?string $roleUuid = null)
 * @method static \OurEdu\RequestTracker\Models\RequestTracker trackAccess(string $userUuid, string $roleUuid, ?string $module = null, ?string $annotation = null, ?\Carbon\Carbon $timestamp = null)
 * @method static \OurEdu\RequestTracker\Models\UserAccessDetail trackModuleAccess(string $userUuid, string $roleUuid, string $module, ?string $submodule = null, ?string $annotation = null, ?string $endpoint = null, ?\Carbon\Carbon $timestamp = null)
 * 
 * @see \OurEdu\RequestTracker\Services\RequestTrackerService
 */
class RequestTracker extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'request-tracker';
    }
}
