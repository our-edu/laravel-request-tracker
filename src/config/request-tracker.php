<?php

return [
    // Enable or disable tracking
    'enabled' => env('REQUEST_TRACKER_ENABLED', false),
    
    // Silent error handling - prevent package errors from breaking your application
    'silent_errors' => env('REQUEST_TRACKER_SILENT_ERRORS', true),

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

    // Module mapping - Auto-detect module from path when no #[TrackModule] attribute
    'module_mapping' => [
        'enabled' => true,
        
        // Custom path patterns to module mapping (highest priority)
        // Format: 'path_pattern' => 'module_name' or ['module' => 'name', 'submodule' => 'sub']
        'patterns' => [
            '/admission/' => ['module' => 'admission'],
            '/subject/' => ['module' => 'subjects'],
            '/certificate_manager/' => ['module' => 'subjects', 'submodule' => 'certificates'],
            '/users/' => ['module' => 'users'],
            '/auth/' => ['module' => 'authentication'],
            '/grades/' => ['module' => 'academic', 'submodule' => 'grades'],
            '/attendance/' => ['module' => 'academic', 'submodule' => 'attendance'],
            // Add your patterns here
        ],
        
        // Auto-extract from path segments (fallback)
        // e.g., 'api/v1/en/subject/certificates/list' -> extract 'subject'
        'auto_extract' => true,
        'auto_extract_segment' => 3, // 0-based index (skip api/v1/locale)
    ],
];

