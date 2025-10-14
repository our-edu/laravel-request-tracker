<?php


return [
    // enable or disable tracking
    'enabled' => env('REQUEST_TRACKER_ENABLED', false),

    'exclude' => [
        // exact path
        'parent/look-up',
    ],

    // Which guard to attempt when resolving user
    'auth_guards' => ['web', 'api'],

];
