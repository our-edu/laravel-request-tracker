<?php

namespace OurEdu\RequestTracker\Models;

use Illuminate\Database\Eloquent\Model;
use OurEdu\RequestTracker\Models\UserAccessDetail;

class RequestTracker extends Model
{

    protected $primaryKey = 'uuid';
    public $keyType = 'uuid';
    public $incrementing = false;
    
    protected $fillable = [
        'uuid',
        'user_uuid',
        'role_uuid',
        'role_name',
        'date',
        'access_count',
        'first_access',
        'last_access',
        'user_session_uuid',
        'ip_address',
        'user_agent',
        'device_type',
        'browser',
        'platform',
    ];

    protected $casts = [
        'date' => 'date',
        'first_access' => 'datetime',
        'last_access' => 'datetime',
        'access_count' => 'integer',
    ];

    public $table = 'request_trackers';

    // Relationships
    public function accessDetails()
    {
        return $this->hasMany(UserAccessDetail::class, 'tracker_uuid', 'uuid');
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

    public function scopeToday($query)
    {
        return $query->whereDate('date', now()->format('Y-m-d'));
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('date', [
            now()->startOfWeek()->format('Y-m-d'),
            now()->endOfWeek()->format('Y-m-d')
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('date', now()->year)
                    ->whereMonth('date', now()->month);
    }

    public function scopeMostActive($query, $limit = 10)
    {
        return $query->orderBy('access_count', 'desc')->limit($limit);
    }
}
