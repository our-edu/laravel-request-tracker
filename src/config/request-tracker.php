<?php


return [
    // enable or disable tracking
    'enabled' => env('REQUEST_TRACKER_ENABLED', true),

    // If true, package will programmatically push middleware to 'api' group for lower-level timing.
    // If false, event-only listeners are used (no middleware).
    'auto_push_middleware_to_api_group' => env('REQUEST_TRACKER_PUSH_MW', false),

    // Which guard to attempt when resolving user
    'auth_guards' => ['web', 'api'],

];
