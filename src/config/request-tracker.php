<?php

return [
    // Enable or disable tracking
    'enabled' => env('REQUEST_TRACKER_ENABLED', false),

    // Paths to exclude from tracking
    'exclude' => [
        // Exact path matches (suffix matching)
        'parent/look-up',
        '', // Root path /
        
        // Wildcard patterns
        // 'api/*/internal',
        
        // Regex patterns (prefix with "regex:")
        // 'regex:/^health/',
    ],

    // Which guards to use when resolving authenticated user
    'auth_guards' => ['web', 'api'],

    // Detailed logging configuration
    'detailed_logging' => [
        // Enable per-request detailed logs (access_logs table)
        'enabled' => env('REQUEST_TRACKER_DETAILED_LOGGING', false),
        
        // Log request payloads (be careful with sensitive data)
        'log_request_payload' => false,
        
        // Log response data
        'log_response_data' => false,
        
        // Maximum payload size to log (in kilobytes)
        'max_payload_size' => 10,
        
        // Only log slow requests (threshold in milliseconds, null = log all)
        'slow_request_threshold' => null, // e.g., 1000 for requests over 1 second
        
        // Only log failed requests (status >= 400)
        'log_only_errors' => false,
    ],

    // Data retention
    'retention' => [
        // Automatic cleanup enabled
        'auto_cleanup' => env('REQUEST_TRACKER_AUTO_CLEANUP', false),
        
        // Number of days to keep daily summary records (request_trackers)
        'keep_summaries_days' => 90,
        
        // Number of days to keep detailed logs (access_logs)
        'keep_detailed_days' => 30,
    ],

    // Module mapping - Extract module name from path
    'module_mapping' => [
        'enabled' => true,
        
        // Map path patterns to module names with optional annotations
        // Format: 'pattern' => 'module' or 'module.submodule' or 'module.submodule|Annotation'
        'patterns' => [
            'api/v1/users' => 'users|User Management',
            // Add more patterns as needed
        ],
        
        // Fallback: extract module from path automatically
        // e.g., 'api/v1/users/123' -> 'users'
        'auto_extract' => true,
        'auto_extract_segment' => 2, // 0-based index of path segment (after api/v1)
    ],

    // Performance
    'performance' => [
        // Queue the database writes (requires queue worker)
        'use_queue' => env('REQUEST_TRACKER_USE_QUEUE', false),
        
        // Queue connection to use
        'queue_connection' => env('REQUEST_TRACKER_QUEUE_CONNECTION', 'default'),
        
        // Queue name
        'queue_name' => 'tracker',
    ],
];

