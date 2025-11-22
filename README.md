# Laravel Request Tracker

Event-driven Laravel package for tracking user access patterns with **daily summaries**, **detailed journeys**, and **module-level analytics**. Automatically logs user activity in background jobs without slowing down your application.

## Features

✅ **Daily Activity Summary** - One record per user+role+date with access count & timestamps  
✅ **Detailed User Journey** - Track unique endpoints with module/submodule organization  
✅ **PHP 8 Attributes** - Use `#[TrackModule('module', 'submodule')]` on controllers  
✅ **Queue-Based** - All tracking via background jobs (respects `QUEUE_CONNECTION`)  
✅ **National ID Support** - Commands filter by national_id for easier access  
✅ **Role Name Storage** - Store role names alongside UUIDs for filtering  
✅ **Silent Errors** - Tracking failures won't break your application  
✅ **Auto-Detection** - Extract modules from URLs, route names, or controller names  
✅ **Data Retention** - Automatic cleanup of old records  

---

## Quick Start

```bash
# Install
composer require our-education/laravel-request-tracker

# Publish & migrate
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider"
php artisan migrate

# Enable in .env
REQUEST_TRACKER_ENABLED=true
QUEUE_CONNECTION=redis

# Start queue worker
php artisan queue:work
```

**Add to controllers:**
```php
use OurEdu\RequestTracker\Attributes\TrackModule;

#[TrackModule('orders')]
class OrderController extends Controller {
    // All methods tracked as 'orders' module
}

#[TrackModule('users', 'profile')]
class ProfileController extends Controller {
    // Tracked as 'users.profile' (module.submodule)
}
```

**View reports:**
```bash
php artisan tracker:user-stats 1234567890
php artisan tracker:user-journey 1234567890 --date=2025-01-15
php artisan tracker:module-access orders --from=2025-01-01
```

---

## How It Works

**Architecture:** `Request` → `RequestHandled Event` → `EventsSubscriber` → `TrackUserAccessJob` (queue) → `Database`

1. After a request completes (post-auth middleware), `RequestHandled` event fires
2. `EventsSubscriber` extracts user, role, device info and dispatches job to queue
3. `TrackUserAccessJob` processes in background:
   - Creates/updates daily summary in `request_trackers` table
   - Checks for `#[TrackModule]` attribute on controller
   - If found, creates detailed record in `user_access_details` table
4. Your application responds immediately (no blocking)

**What's Tracked:**
- **Daily Summary** (all requests): User+role+date, access count, timestamps, device info
- **Detailed Journey** (attributed controllers only): Endpoint, module, submodule, action, visit count

---

## Configuration

**Environment Variables:**
```env
REQUEST_TRACKER_ENABLED=true              # Enable/disable tracking
REQUEST_TRACKER_SILENT_ERRORS=true        # Don't break app on errors
REQUEST_TRACKER_AUTO_CLEANUP=false        # Auto-delete old records
QUEUE_CONNECTION=redis                    # Laravel's queue (sync/redis/database)
```

**Config File** (`config/request-tracker.php`):

```php
return [
    'enabled' => env('REQUEST_TRACKER_ENABLED', false),
    'silent_errors' => env('REQUEST_TRACKER_SILENT_ERRORS', true),
    
    // Exclude paths from tracking
    'exclude' => [
        'parent/look-up',         // Suffix match
        'api/*/internal',         // Wildcard
        'regex:/^health/',        // Regex
    ],
    
    // Auth guards to check
    'auth_guards' => ['web', 'api'],
    
    // Data retention
    'retention' => [
        'auto_cleanup' => env('REQUEST_TRACKER_AUTO_CLEANUP', false),
        'keep_summaries_days' => 90,
        'keep_detailed_days' => 30,
    ],
    
    // Module auto-extraction from URLs
    'module_mapping' => [
        'enabled' => true,
        'patterns' => [
            'api/v1/users' => 'users|User Management',
            'api/v1/users/profile' => 'users.profile|User Profile',
        ],
        'auto_extract' => true,
        'auto_extract_segment' => 2,
    ],
];
```

---

## Usage

### 1. Add Attributes to Controllers

```php
use OurEdu\RequestTracker\Attributes\TrackModule;

// Simple module
#[TrackModule('orders')]
class OrderController extends Controller {
    public function index() { /* module='orders', action='index' */ }
    public function show($id) { /* module='orders', action='show' */ }
}

// Module + Submodule
#[TrackModule('users', 'profile')]
class ProfileController extends Controller {
    public function show($id) { /* module='users', submodule='profile' */ }
}

// Multiple controllers, same parent module
#[TrackModule('reports', 'sales')]
class SalesReportController { }

#[TrackModule('reports', 'inventory')]
class InventoryReportController { }
```

**Action derivation:** Route name last segment (e.g., `users.profile.show` → `show`) or method name.

**Without attribute:** Daily summary still tracked, but no detailed journey records.

### 2. Query the Data

```php
use OurEdu\RequestTracker\Models\{RequestTracker, UserAccessDetail};

// Get today's summary
$summary = RequestTracker::forUser($userUuid)->today()->first();

// Get user's journey
$journey = UserAccessDetail::forUser($userUuid)
    ->forDate('2025-01-15')
    ->get();

// Most visited modules this month
$modules = UserAccessDetail::forUser($userUuid)
    ->thisMonth()
    ->select('module', DB::raw('SUM(visit_count) as total'))
    ->groupBy('module')
    ->orderByDesc('total')
    ->get();

// Find who accessed a module
$users = UserAccessDetail::whoAccessedModule('orders', '2025-01-01', '2025-01-31')
    ->get();
```

### 3. Use Artisan Commands

```bash
# User stats (by national_id)
php artisan tracker:user-stats 1234567890
php artisan tracker:user-stats 1234567890 --from=2025-01-01 --to=2025-01-31
php artisan tracker:user-stats 1234567890 --role={role-uuid}

# User journey
php artisan tracker:user-journey 1234567890 --date=2025-01-15
php artisan tracker:user-journey 1234567890 --module=users

# Module access report
php artisan tracker:module-access orders --from=2025-01-01 --to=2025-01-31
php artisan tracker:module-access users --submodule=profile --role={role-uuid}

# Cleanup old records
php artisan tracker:cleanup --days=30 --dry-run
```

---

## Database Schema

### `request_trackers` - Daily Summary

**One record per** `user_uuid` + `role_uuid` + `date`

| Column | Type | Description |
|--------|------|-------------|
| `uuid` | UUID | Primary key |
| `user_uuid` | UUID | User identifier |
| `role_uuid` | UUID | Role identifier |
| `role_name` | STRING | Role display name (from `role_translations.display_name` where `locale='en'`) |
| `date` | DATE | Activity date |
| `access_count` | INTEGER | Total requests this day |
| `first_access` | DATETIME | First request timestamp |
| `last_access` | DATETIME | Last request timestamp |
| `user_session_uuid` | UUID | Session identifier |
| `ip_address` | STRING | IP address |
| `user_agent` | TEXT | Browser user agent |
| `device_type` | STRING | mobile/desktop/tablet |
| `browser` | STRING | Browser name |
| `platform` | STRING | Operating system |

### `user_access_details` - Detailed Journey

**One record per unique endpoint** per user per date (only for attributed controllers)

| Column | Type | Description |
|--------|------|-------------|
| `uuid` | UUID | Primary key |
| `tracker_uuid` | UUID | FK to `request_trackers.uuid` |
| `user_uuid` | UUID | User identifier |
| `role_uuid` | UUID | Role identifier |
| `role_name` | STRING | Role display name |
| `date` | DATE | Visit date |
| `method` | STRING | HTTP method (GET/POST/PUT/DELETE) |
| `endpoint` | TEXT | Full path (e.g., `api/v1/users/123/profile`) |
| `route_name` | STRING | Laravel route name |
| `controller_action` | STRING | Controller@method |
| `module` | STRING | Main module (from `#[TrackModule]`) |
| `submodule` | STRING | Submodule (from `#[TrackModule]`) |
| `action` | STRING | Action name (from route or method) |
| `visit_count` | INTEGER | Times visited today |
| `first_visit` | DATETIME | First visit timestamp |
| `last_visit` | DATETIME | Last visit timestamp |

**Unique Constraint:** `tracker_uuid` + `endpoint` + `method`

---

## Query Scopes

### RequestTracker Scopes
```php
->forUser($uuid)           // Filter by user
->forDate($date)           // Specific date
->forDateRange($from, $to) // Date range
->today()                  // Today
->thisWeek()               // This week
->thisMonth()              // This month
->mostActive($limit)       // Top active users
```

### UserAccessDetail Scopes
```php
->forUser($uuid)
->forModule($module)
->forSubmodule($submodule)
->forDate($date)
->forDateRange($from, $to)
->today()
->thisWeek()
->thisMonth()
->mostVisited($limit)
->groupByModule()
->whoAccessedModule($module, $from, $to)
->whoAccessedSubmodule($module, $submodule, $from, $to)
```

---

## Module Detection Priority

1. **`#[TrackModule]` Attribute** (highest priority) - Class-level attribute
2. **Custom Patterns** - Defined in `config/request-tracker.php`
3. **Route Name** - Dot notation (e.g., `users.profile.show`)
4. **URL Path** - Auto-extract from path segments
5. **Controller Name** - Extract from controller class name

---

## Advanced Features

### Path Exclusion
```php
'exclude' => [
    'health',                // Suffix match: */health
    'api/*/internal',        // Wildcard: api/v1/internal, api/v2/internal
    'regex:/^(ping|pong)/',  // Regex: starts with ping or pong
]
```

### Queue Integration
Package respects Laravel's `QUEUE_CONNECTION`:
- `sync` - Immediate processing (dev only)
- `redis` - Background processing (recommended)
- `database` - Database queue
- `sqs` - AWS SQS

**Important:** Run `php artisan queue:work` in production!

### Data Retention
```bash
# Schedule in app/Console/Kernel.php
protected function schedule(Schedule $schedule) {
    $schedule->command('tracker:cleanup --days=90')->daily();
}
```

---

## Troubleshooting

**Tracking not working?**
1. Check `REQUEST_TRACKER_ENABLED=true` in `.env`
2. Run `php artisan config:cache`
3. Ensure queue worker is running: `php artisan queue:work`
4. Check logs: `tail -f storage/logs/laravel.log`

**Jobs not processing?**
1. Verify `QUEUE_CONNECTION` is correct
2. Restart worker: `php artisan queue:restart`
3. Check failed jobs: `php artisan queue:failed`

**Attribute not detected?**
1. PHP >= 8.0 required (attributes)
2. Attribute must be at **class level**, not method level
3. Syntax: `#[TrackModule('module', 'submodule')]`

---

## Requirements

- PHP >= 8.0
- Laravel 8, 9, 10, or 11
- Queue worker running (for background processing)

---

## License

MIT License

---

## Support

- **GitHub:** https://github.com/our-edu/laravel-request-tracker
- **Issues:** https://github.com/our-edu/laravel-request-tracker/issues

---

**Made with ❤️ by [Our Education](https://github.com/our-edu)**

