# Laravel User Access Tracker

A comprehensive Laravel package for tracking user access patterns and generating detailed activity reports. Track **user + role daily activity**, **last access time**, **visited endpoints/modules**, and **annotated user journeys** — all automatically without modifying your routes or middleware.

**Event-driven** and **auto-discoverable** — zero configuration needed to start tracking!

---

## 🚀 Quick Start

```php
use OurEdu\RequestTracker\Facades\RequestTracker;

// Get user's last access time - ONE LINE!
$lastAccess = RequestTracker::getLastAccess($userUuid);
echo $lastAccess->diffForHumans(); // "5 minutes ago"

// Check if user is online
if (RequestTracker::isActive($userUuid)) {
    echo "User is online! 🟢";
}
```

**[→ See Quick Start Guide](QUICKSTART.md)** for more examples.

---

## Table of Contents

- [Quick Start](#-quick-start)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Artisan Commands](#artisan-commands)
- [Database Schema](#database-schema)
- [Module Detection](#module-detection)

---

## Features

### 📊 Daily Activity Summary (`request_trackers`)
- ✅ **One record per user + role + date**
- ✅ Track **total request count** per day
- ✅ Track **first and last access times**
- ✅ Unique daily tracking (no duplicates)

### 🗺️ Detailed User Journey (`user_access_details`)
- ✅ **Track each unique endpoint visited**
- ✅ **Module and submodule organization** (e.g., users → profile → settings)
- ✅ **Human-readable annotations** for each endpoint
- ✅ **Visit count** per endpoint per day
- ✅ **Timestamp tracking** (first visit, last visit)

### 🤖 Smart Module Detection
- ✅ Auto-extract modules from URL paths
- ✅ Auto-extract from Laravel route names
- ✅ Auto-extract from controller names
- ✅ Custom pattern mapping with annotations
- ✅ Configurable extraction rules

### 📈 Analytics & Commands
- ✅ View user journey with module breakdown
- ✅ Daily activity summaries
- ✅ Automatic data cleanup
- ✅ Export-ready data structure

---

## Requirements

- PHP >= 8.0
- Laravel 8, 9, 10, or 11
- MySQL, PostgreSQL, or any Laravel-supported database

---

## Installation

### 1) Install via Composer

```bash
composer require our-education/laravel-request-tracker
```

The package uses Laravel's auto-discovery, so the service provider will be registered automatically.

### 2) Publish Configuration & Migrations

```bash
# Publish config file
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="config"

# Publish migrations
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="migrations"
```

### 3) Run Migrations

```bash
php artisan migrate
```

This creates two tables:
- `request_trackers` - Daily user + role activity summary
- `user_access_details` - Detailed endpoint visits with modules

---

## Configuration

Edit `config/request-tracker.php`:

```php
return [
    // Enable/disable tracking
    'enabled' => env('REQUEST_TRACKER_ENABLED', false),

    // Paths to exclude from tracking
    'exclude' => [
        'health',             // Suffix match
        '',                   // Root path
        'api/*/internal',     // Wildcard
        'regex:/^admin/',     // Regex pattern
    ],

    // Auth guards to check for users
    'auth_guards' => ['web', 'api'],

    // Module mapping with annotations
    'module_mapping' => [
        'enabled' => true,
        'patterns' => [
            'api/v1/users' => 'users|User Management',
            'api/v1/users/profile' => 'users.profile|User Profile',
            'api/v1/orders' => 'orders|Order Management',
        ],
        'auto_extract' => true,
        'auto_extract_segment' => 2, // Path segment index
    ],
];
```

### Enable Tracking

Add to your `.env`:

```env
REQUEST_TRACKER_ENABLED=true
```

---

## Usage

### 1️⃣ **Add Attributes to Your Controllers** (Recommended)

The easiest way to track modules is using PHP 8 attributes:

```php
use OurEdu\RequestTracker\Attributes\TrackRequest;

class OrderController extends Controller
{
    #[TrackRequest('orders|Order Management')]
    public function index()
    {
        return Order::paginate();
    }
    
    #[TrackRequest('orders.details|View Order Details')]
    public function show($id)
    {
        return Order::findOrFail($id);
    }
    
    #[TrackRequest('orders.create|Create New Order')]
    public function store(Request $request)
    {
        return Order::create($request->all());
    }
}

class ReportController extends Controller
{
    #[TrackRequest('reports.sales|Sales Analytics Dashboard')]
    public function salesReport()
    {
        // Your report logic
    }
    
    #[TrackRequest('reports.inventory|Inventory Report')]
    public function inventoryReport()
    {
        // Your report logic
    }
}
```

### 2️⃣ **Get User's Last Access Time (Simple!)**

The easiest way to get user activity data:

```php
use OurEdu\RequestTracker\Facades\RequestTracker;

// Get user's last access time
$lastAccess = RequestTracker::getLastAccess($userUuid);
echo $lastAccess->diffForHumans(); // "5 minutes ago"

// Get last access for specific role
$lastAccess = RequestTracker::getLastAccess($userUuid, $roleUuid);

// Check if user is currently active (last 5 minutes)
if (RequestTracker::isActive($userUuid)) {
    echo "User is online!";
}

// Check with custom threshold (last 15 minutes)
if (RequestTracker::isActive($userUuid, minutesThreshold: 15)) {
    echo "User is active!";
}

// Get today's activity summary
$today = RequestTracker::getTodayActivity($userUuid);
/*
[
    'active' => true,
    'access_count' => 45,
    'first_access' => Carbon instance,
    'last_access' => Carbon instance,
    'modules_visited' => [
        'users' => 8,      // 8 unique endpoints
        'orders' => 5,
        'reports' => 3
    ]
]
*/

// Get activity summary for date range
$summary = RequestTracker::getActivitySummary(
    $userUuid, 
    now()->subMonth(), 
    now()
);
/*
[
    'total_days' => 22,
    'total_requests' => 1250,
    'first_access' => Carbon,
    'last_access' => Carbon,
    'daily_average' => 56.82
]
*/

// Get all modules user accessed
$modules = RequestTracker::getModulesAccessed($userUuid);
foreach ($modules as $module) {
    echo "{$module->module}: {$module->total_visits} visits\n";
}
```

### 3️⃣ **Query Who Accessed What**

```php
use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\UserAccessDetail;
use Carbon\Carbon;

// Get today's summary for a user
$summary = RequestTracker::forUser($userUuid)->today()->first();

// Get all endpoints visited by user today
$journey = UserAccessDetail::forUser($userUuid)->today()->get();

// Group by module
$byModule = UserAccessDetail::forUser($userUuid)
    ->today()
    ->get()
    ->groupBy('module');

// Most visited endpoints
$popular = UserAccessDetail::forUser($userUuid)
    ->thisWeek()
    ->mostVisited(10)
    ->get();

// Find all users who accessed a specific module
$startDate = Carbon::now()->subWeek();
$endDate = Carbon::now();

$users = UserAccessDetail::whoAccessedModule('products', $startDate, $endDate)->get();

// Filter by specific role
$usersWithRole = UserAccessDetail::whoAccessedModule('settings', $startDate, $endDate)
    ->where('role_uuid', $specificRoleUuid)
    ->get();

// Find users who accessed a specific submodule
$users = UserAccessDetail::whoAccessedSubmodule('users', 'profile', $startDate, $endDate)->get();
```

---

## Artisan Commands

### 1. Find Who Accessed a Module

Find all users who accessed a specific module within a date range:

```bash
# Basic usage - last 7 days
php artisan tracker:module-access products

# Custom date range
php artisan tracker:module-access products --from=2025-01-01 --to=2025-01-31

# Filter by role
php artisan tracker:module-access settings --role={role-uuid}

# Filter by submodule
php artisan tracker:module-access users --submodule=profile

# Limit results
php artisan tracker:module-access orders --limit=50
```

**Output Example:**
```
Module Access Report: products
Date Range: 2025-01-01 to 2025-01-31

┌──────────────────────┬──────────────────────┬──────────┬──────────────┬─────────────────────┬─────────────────────┐
│ User UUID            │ Role UUID            │ Visits   │ Endpoints    │ First Access        │ Last Access         │
├──────────────────────┼──────────────────────┼──────────┼──────────────┼─────────────────────┼─────────────────────┤
│ abc123-def456...     │ xyz789-uvw123...     │ 45       │ 8            │ 2025-01-05 08:30:15 │ 2025-01-15 17:45:30 │
│ ghi789-jkl012...     │ mno345-pqr678...     │ 32       │ 5            │ 2025-01-10 09:15:22 │ 2025-01-20 16:20:10 │
└──────────────────────┴──────────────────────┴──────────┴──────────────┴─────────────────────┴─────────────────────┘

Total Users: 2
Total Visits: 77
```

### 2. View User Journey

View detailed user journey with module breakdown:

```bash
# View user journey for today
php artisan tracker:user-journey {user-uuid}

# Specific date
php artisan tracker:user-journey {user-uuid} --date=2025-01-15

# Filter by module
php artisan tracker:user-journey {user-uuid} --module=users

# Filter by role
php artisan tracker:user-journey {user-uuid} --role={role-uuid}
```

**Output Example:**
```
🗺️  User Journey for: abc123-def456-...
📅 Date: 2025-01-15

📊 Daily Summary:
┌─────────────────┬────────────────────┐
│ Metric          │ Value              │
├─────────────────┼────────────────────┤
│ User UUID       │ abc123-def456-...  │
│ Role UUID       │ xyz789-uvw123-...  │
│ Total Requests  │ 45                 │
│ First Access    │ 08:30:15           │
│ Last Access     │ 17:45:30           │
└─────────────────┴────────────────────┘

🎯 Modules Accessed:
┌────────────┬───────────────────┬──────────────┐
│ Module     │ Unique Endpoints  │ Total Visits │
├────────────┼───────────────────┼──────────────┤
│ users      │ 8                 │ 25           │
│ orders     │ 5                 │ 15           │
│ reports    │ 3                 │ 5            │
└────────────┴───────────────────┴──────────────┘

📍 Detailed Endpoint Visits:
┌──────────┬─────────┬────────────┬─────────────────────────┬────────┬───────────────────┐
│ Time     │ Module  │ Submodule  │ Endpoint                │ Visits │ Annotation        │
├──────────┼─────────┼────────────┼─────────────────────────┼────────┼───────────────────┤
│ 08:30:15 │ users   │ profile    │ api/v1/users/123/profile│ 5      │ User Profile      │
│ 09:15:22 │ orders  │ -          │ api/v1/orders           │ 10     │ Order Management  │
│ 10:45:10 │ users   │ settings   │ api/v1/users/123/set... │ 3      │ User Settings     │
└──────────┴─────────┴────────────┴─────────────────────────┴────────┴───────────────────┘
```

### 3. View User Statistics

```bash
# Overall statistics for today
php artisan tracker:user-stats

# Specific user
php artisan tracker:user-stats {user-uuid}

# Date range
php artisan tracker:user-stats --from=2025-01-01 --to=2025-01-31
```

### 4. Cleanup Old Records

```bash
# Delete records older than 30 days
php artisan tracker:cleanup --days=30

# Dry run (preview)
php artisan tracker:cleanup --days=30 --dry-run
```

---

## Database Schema

### `request_trackers` Table (Daily Summary)

| Column | Type | Description |
|--------|------|-------------|
| `uuid` | UUID | Primary key |
| `user_uuid` | UUID | User identifier |
| `role_uuid` | UUID | User's role |
| `date` | DATE | Date of activity |
| `access_count` | INTEGER | Total requests this day |
| `first_access` | DATETIME | First request timestamp |
| `last_access` | DATETIME | Last request timestamp |
| `application` | STRING | Application name |

**Unique constraint:** `user_uuid + role_uuid + date`

### `user_access_details` Table (Visited Endpoints)

| Column | Type | Description |
|--------|------|-------------|
| `uuid` | UUID | Unique identifier |
| `tracker_uuid` | UUID | Links to request_trackers |
| `user_uuid` | UUID | User identifier |
| `role_uuid` | UUID | User's role |
| `date` | DATE | Date of visit |
| `method` | STRING | HTTP method |
| `endpoint` | TEXT | Full path |
| `route_name` | STRING | Laravel route name |
| `module` | STRING | Main module (e.g., "users") |
| `submodule` | STRING | Sub-module (e.g., "profile") |
| `annotation` | STRING | Human-readable description |
| `visit_count` | INTEGER | Times visited today |
| `first_visit` | DATETIME | First visit timestamp |
| `last_visit` | DATETIME | Last visit timestamp |

**Unique constraint:** `user_uuid + role_uuid + endpoint + date`

---

## Module Detection

The package intelligently extracts modules from your requests with **5 detection strategies** (in priority order):

### 1. PHP 8 Attributes `#[TrackRequest]` ⭐ (Highest Priority - **NEW!**)

Use the `#[TrackRequest]` attribute directly on your controller methods:

```php
use OurEdu\RequestTracker\Attributes\TrackRequest;

class UserController extends Controller
{
    #[TrackRequest('users.profile|User Profile Management')]
    public function showProfile($id)
    {
        // Your code here
    }
    
    #[TrackRequest('users.settings|Account Settings')]
    public function updateSettings(Request $request)
    {
        // Your code here
    }
    
    #[TrackRequest('users|User Management')]
    public function index()
    {
        // Your code here
    }
}
```

**Format:** `#[TrackRequest('module.submodule|Human Readable Annotation')]`

**Examples:**
- `#[TrackRequest('orders')]` - Module only
- `#[TrackRequest('orders.history')]` - Module + submodule
- `#[TrackRequest('orders.history|Order History Dashboard')]` - Full annotation

### 2. Custom Pattern Mapping (Config)

```php
'patterns' => [
    'api/v1/users/profile' => 'users.profile|User Profile Settings',
    'api/v1/orders' => 'orders|Order Management',
],
```

Format: `'pattern' => 'module.submodule|Annotation'`

### 3. Route Name Detection

```php
// Route: users.profile.show
// Result: module=users, submodule=profile, annotation="Show Profile"
Route::get('/users/{id}/profile', [UserController::class, 'showProfile'])
    ->name('users.profile.show');
```

### 4. Path Segment Extraction

```php
// Path: api/v1/users/123/settings
// Result: module=users, submodule=settings
```

### 5. Controller Name Detection

```php
// Controller: UserController@updateProfile
// Result: module=user, annotation="Update Profile User"
```

---

## Query Scopes

### RequestTracker Model

```php
->forUser($userUuid)              // Filter by user
->forDate($date)                  // Specific date
->forDateRange($start, $end)      // Date range
->today()                         // Today's records
->thisWeek()                      // This week
->thisMonth()                     // This month
->mostActive($limit)              // Top active users
```

### UserAccessDetail Model

```php
->forUser($userUuid)
->forModule($module)
->forSubmodule($submodule)
->forDate($date)
->forDateRange($start, $end)
->today()
->mostVisited($limit)
->groupByModule()                 // Aggregate by module
```

---

## Package Structure

```
laravel-request-tracker/
├── database/
│   └── migrations/
│       ├── create_request_trackers_table.php
│       └── create_user_access_details_table.php
├── src/
│   ├── RequestTrackerServiceProvider.php
│   ├── config/request-tracker.php
│   ├── Models/
│   │   ├── RequestTracker.php
│   │   └── UserAccessDetail.php
│   ├── Listeners/
│   │   └── EventsSubscriber.php
│   ├── Services/
│   │   └── ModuleExtractor.php
│   └── Console/Commands/
│       ├── UserJourneyCommand.php
│       ├── UserAccessStatsCommand.php
│       └── CleanupLogsCommand.php
```

---

## Perfect For

✅ **User behavior analytics** - Understand what users actually do  
✅ **Compliance & auditing** - Track who accessed what and when  
✅ **Feature usage tracking** - See which modules are most popular  
✅ **Access pattern analysis** - Identify user workflows  
✅ **Security monitoring** - Detect unusual access patterns  

---

## License

MIT

## Support

Issues: https://github.com/our-edu/laravel-request-tracker/issues

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Artisan Commands](#artisan-commands)
- [Database Schema](#database-schema)
- [Query Scopes](#query-scopes)
- [Advanced Features](#advanced-features)

---

## Features

### Daily Summary Tracking (`request_trackers`)
- ✅ **One record per user + role + date** (not per request)
- ✅ Track **total access count** per day
- ✅ Track **first and last access times**
- ✅ Capture **route names** and **controller actions**
- ✅ Store **IP address** and **user agent**
- ✅ Track **response times** and **status codes**
- ✅ **Module-based filtering** (e.g., users, orders, products)

### Detailed Request Logging (`access_logs`) - Optional
- ✅ Per-request detailed logs
- ✅ Full request/response payload capture (configurable)
- ✅ Error tracking with exception details
- ✅ Slow request identification
- ✅ Query string tracking

### Analytics & Reporting
- ✅ **Artisan commands** for viewing statistics
- ✅ **Query scopes** for common analytics queries
- ✅ **Automatic data retention** and cleanup
- ✅ Top users, routes, and modules reports

### Developer Experience
- ✅ Event-driven (no middleware modifications needed)
- ✅ Auto-discoverable service provider
- ✅ Highly configurable
- ✅ Queue support for async processing
- ✅ Comprehensive exclusion patterns (regex, wildcards, exact)

---

## Requirements

- PHP >= 8.0
- Laravel 8, 9, 10, or 11
- MySQL, PostgreSQL, or any Laravel-supported database

---

## Installation

### 1) Install via Composer

```bash
composer require our-education/laravel-request-tracker
```

The package uses Laravel's auto-discovery, so the service provider will be registered automatically.

### 2) Publish Configuration & Migrations

```bash
# Publish config file
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="config"

# Publish migrations
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="migrations"
```

### 3) Run Migrations

```bash
php artisan migrate
```

This creates two tables:
- `request_trackers` - Daily summary of user access
- `access_logs` - Detailed per-request logs (optional)

---

## Configuration

Edit `config/request-tracker.php`:

```php
return [
    // Enable/disable tracking
    'enabled' => env('REQUEST_TRACKER_ENABLED', false),

    // Paths to exclude from tracking
    'exclude' => [
        'parent/look-up',     // Suffix match
        '',                   // Root path
        'api/*/internal',     // Wildcard
        'regex:/^health/',    // Regex pattern
    ],

    // Auth guards to check for users
    'auth_guards' => ['web', 'api'],

    // Detailed logging (optional)
    'detailed_logging' => [
        'enabled' => false,
        'log_request_payload' => false,
        'log_response_data' => false,
        'slow_request_threshold' => 1000, // ms
        'log_only_errors' => false,
    ],

    // Data retention
    'retention' => [
        'auto_cleanup' => false,
        'keep_summaries_days' => 90,
        'keep_detailed_days' => 30,
    ],

    // Module mapping
    'module_mapping' => [
        'enabled' => true,
        'patterns' => [
            'api/v1/users' => 'users',
            'api/v1/orders' => 'orders',
        ],
        'auto_extract' => true,
    ],
];
```

### Enable Tracking

Add to your `.env`:

```env
REQUEST_TRACKER_ENABLED=true
REQUEST_TRACKER_DETAILED_LOGGING=false
REQUEST_TRACKER_AUTO_CLEANUP=true
```

---

## Usage

Once enabled, tracking happens automatically for all authenticated requests!

### Basic Query Examples

```php
use OurEdu\RequestTracker\Models\RequestTracker;

// Get today's access for a user
$todayAccess = RequestTracker::forUser($userUuid)->today()->first();

// Get this week's activity
$weekActivity = RequestTracker::forUser($userUuid)->thisWeek()->get();

// Get most accessed routes
$topRoutes = RequestTracker::mostAccessed(10)->get();

// Get access for specific date range
$records = RequestTracker::forDateRange('2025-01-01', '2025-01-31')->get();
```

### Working with Access Logs

```php
use OurEdu\RequestTracker\Models\AccessLog;

// Get all errors today
$errors = AccessLog::errors()->today()->get();

// Get slow requests (> 1 second)
$slowRequests = AccessLog::slowRequests(1000)->get();

// Get successful requests for a module
$moduleAccess = AccessLog::forModule('users')->successful()->get();
```

---

## Artisan Commands

### View User Statistics

```bash
# Overall statistics for today
php artisan tracker:user-stats

# Specific user statistics
php artisan tracker:user-stats {user-uuid}

# Date range
php artisan tracker:user-stats --from=2025-01-01 --to=2025-01-31

# Top 20 users
php artisan tracker:user-stats --top=20
```

**Output Example:**
```
📊 Overall Access Statistics
Period: Today

┌─────────────────────┬────────┐
│ Metric              │ Value  │
├─────────────────────┼────────┤
│ Total Users         │ 150    │
│ Total Requests      │ 3,420  │
│ Unique Routes       │ 45     │
│ Avg Response Time   │ 234 ms │
└─────────────────────┴────────┘

👥 Top 10 Most Active Users:
┌──────────────────┬─────────────────┬──────────────┐
│ User UUID        │ Total Requests  │ Days Active  │
├──────────────────┼─────────────────┼──────────────┤
│ abc123...        │ 450             │ 15           │
└──────────────────┴─────────────────┴──────────────┘
```

### Cleanup Old Records

```bash
# Delete records older than 30 days
php artisan tracker:cleanup --days=30

# Dry run (see what would be deleted)
php artisan tracker:cleanup --days=30 --dry-run
```

---

## Database Schema

### `request_trackers` Table (Daily Summaries)

| Column | Type | Description |
|--------|------|-------------|
| `uuid` | UUID | Primary key |
| `user_uuid` | UUID | User identifier |
| `role_uuid` | UUID | User's role |
| `date` | DATE | Date of access |
| `access_count` | INTEGER | Number of requests this day |
| `first_access` | DATETIME | First request timestamp |
| `last_access` | DATETIME | Most recent request |
| `route_name` | STRING | Last accessed route |
| `controller_action` | STRING | Controller@method |
| `ip_address` | STRING | IP address |
| `user_agent` | TEXT | Browser/client info |
| `status_code` | INTEGER | Last HTTP status |
| `response_time` | INTEGER | Last response time (ms) |

### `access_logs` Table (Detailed Logs - Optional)

Complete per-request logging including payloads, errors, and full request context.

---

## Query Scopes

### RequestTracker Model

```php
->forUser($userUuid)              // Filter by user
->forDate($date)                  // Specific date
->forDateRange($start, $end)      // Date range
->forRoute($routeName)            // Specific route
->forModule($module)              // Module filter
->today()                         // Today's records
->thisWeek()                      // This week
->thisMonth()                     // This month
->mostAccessed($limit)            // Top accessed
```

### AccessLog Model

```php
->forUser($userUuid)
->forRoute($routeName)
->forModule($module)
->errors()                        // Status >= 400
->slowRequests($ms)               // Response time > threshold
->successful()                    // Status 200-299
->failed()                        // Status >= 400
->today()
```

---

## Advanced Features

### Module Auto-Detection

The package automatically extracts module names from URLs:

```php
// URL: api/v1/users/123
// Module: "users"

// Configure in config/request-tracker.php
'module_mapping' => [
    'auto_extract' => true,
    'auto_extract_segment' => 2, // 0-based index
],
```

### Exclusion Patterns

```php
'exclude' => [
    'exact/path',              // Exact suffix match
    'api/*/health',            // Wildcard pattern  
    'regex:/^(health|ping)/',  // Regex pattern
],
```

### Queue Support

For high-traffic applications, enable queued processing:

```env
REQUEST_TRACKER_USE_QUEUE=true
REQUEST_TRACKER_QUEUE_CONNECTION=redis
```

---

## Package Structure

```
laravel-request-tracker/
├── composer.json
├── README.md
├── phpunit.xml
├── database/
│   └── migrations/
│       ├── 2025_01_01_000000_create_request_trackers_table.php
│       └── 2025_01_01_000001_create_access_logs_table.php
├── src/
│   ├── RequestTrackerServiceProvider.php
│   ├── config/
│   │   └── request-tracker.php
│   ├── Models/
│   │   ├── RequestTracker.php
│   │   └── AccessLog.php
│   ├── Listeners/
│   │   └── EventsSubscriber.php
│   └── Console/
│       └── Commands/
│           ├── UserAccessStatsCommand.php
│           └── CleanupLogsCommand.php
└── tests/
    └── TestCase.php
```