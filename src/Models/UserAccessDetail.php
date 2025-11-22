<?php

namespace OurEdu\RequestTracker\Models;

use Illuminate\Database\Eloquent\Model;

class UserAccessDetail extends Model
{
    protected $table = 'user_access_details';
    
    protected $fillable = [
        'uuid',
        'tracker_uuid',
        'user_uuid',
        'role_uuid',
        'date',
        'method',
        'endpoint',
        'route_name',
        'controller_action',
        'module',
        'submodule',
        'action',
        'visit_count',
        'first_visit',
        'last_visit',
    ];

    protected $casts = [
        'date' => 'date',
        'first_visit' => 'datetime',
        'last_visit' => 'datetime',
        'visit_count' => 'integer',
    ];

    // Relationships
    public function tracker()
    {
        return $this->belongsTo(RequestTracker::class, 'tracker_uuid', 'uuid');
    }

    // Query Scopes
    public function scopeForUser($query, $userUuid)
    {
        return $query->where('user_uuid', $userUuid);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeForModule($query, $module)
    {
        return $query->where('module', $module);
    }

    public function scopeForSubmodule($query, $submodule)
    {
        return $query->where('submodule', $submodule);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('date', now()->format('Y-m-d'));
    }

    public function scopeGroupByModule($query)
    {
        return $query->selectRaw('module, count(*) as total_visits, sum(visit_count) as total_requests')
                    ->groupBy('module')
                    ->orderByDesc('total_requests');
    }

    public function scopeMostVisited($query, $limit = 10)
    {
        return $query->orderBy('visit_count', 'desc')->limit($limit);
    }
    
    // Get all users who accessed a specific module
    public function scopeWhoAccessedModule($query, $module, $startDate = null, $endDate = null)
    {
        $query->where('module', $module);
        
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }
        
        return $query->select('user_uuid', 'role_uuid')
                    ->selectRaw('COUNT(DISTINCT endpoint) as unique_endpoints')
                    ->selectRaw('SUM(visit_count) as total_visits')
                    ->selectRaw('MIN(first_visit) as first_access')
                    ->selectRaw('MAX(last_visit) as last_access')
                    ->groupBy('user_uuid', 'role_uuid')
                    ->orderByDesc('total_visits');
    }
    
    // Get users who accessed specific submodule
    public function scopeWhoAccessedSubmodule($query, $module, $submodule, $startDate = null, $endDate = null)
    {
        $query->where('module', $module)
              ->where('submodule', $submodule);
        
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }
        
        return $query->select('user_uuid', 'role_uuid')
                    ->selectRaw('COUNT(DISTINCT endpoint) as unique_endpoints')
                    ->selectRaw('SUM(visit_count) as total_visits')
                    ->selectRaw('MIN(first_visit) as first_access')
                    ->selectRaw('MAX(last_visit) as last_access')
                    ->groupBy('user_uuid', 'role_uuid')
                    ->orderByDesc('total_visits');
    }
}

