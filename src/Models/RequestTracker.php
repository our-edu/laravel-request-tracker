<?php

namespace OurEdu\RequestTracker\Models;

use Illuminate\Database\Eloquent\Model;

class RequestTracker extends Model
{

    protected $primaryKey = 'uuid';
    public $keyType = 'uuid';
    public $incrementing = false;
    protected $fillable = [
        'uuid',
        'user_uuid',
        'method',
        'role_uuid',
        'application',
        'auth_guards',
        'user_session_uuid',
        'last_access'   ,
        'path'
    ];

    public $table = 'request_trackers';

}