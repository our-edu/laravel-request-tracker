# Installation Guide - Laravel Request Tracker

Complete step-by-step guide to install and use this package in any Laravel project.

---

## 📋 Prerequisites

- PHP >= 8.0
- Laravel 8, 9, 10, or 11
- MySQL, PostgreSQL, or any Laravel-supported database
- Composer installed

---

## 🚀 Installation Steps

### Step 1: Install the Package

```bash
cd /path/to/your/laravel/project

composer require our-education/laravel-request-tracker
```

**Note:** The package uses Laravel's auto-discovery, so the service provider is registered automatically.

---

### Step 2: Publish Configuration & Migrations

```bash
# Publish config file
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="config"

# Publish migrations
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="migrations"
```

This creates:
- `config/request-tracker.php` - Configuration file
- `database/migrations/*_create_request_trackers_table.php`
- `database/migrations/*_create_access_logs_table.php`

---

### Step 3: Run Migrations

```bash
php artisan migrate
```

This creates two tables:
- `request_trackers` - Daily summary (user + role + date)
- `user_access_details` - Detailed endpoint visits

---

### Step 4: Enable Tracking

Add to your `.env` file:

```env
REQUEST_TRACKER_ENABLED=true
```

---

### Step 5: Configure (Optional)

Edit `config/request-tracker.php`:

```php
return [
    // Enable/disable tracking
    'enabled' => env('REQUEST_TRACKER_ENABLED', false),

    // Paths to exclude from tracking
    'exclude' => [
        'health',                 // Health check endpoint
        'api/internal',           // Internal APIs
        'regex:/^admin\/logs/',   // Admin logs (regex)
    ],

    // Auth guards to check for users
    'auth_guards' => ['web', 'api'],

    // Module mapping with annotations
    'module_mapping' => [
        'enabled' => true,
        'patterns' => [
            'api/v1/users' => 'users|User Management',
            'api/v1/orders' => 'orders|Order Management',
            'api/v1/products' => 'products|Product Catalog',
        ],
    ],
];
```

---

## ✅ That's It! Now You Can Use It

### Automatic Tracking (Zero Code Required)

Once enabled, the package **automatically tracks** all authenticated requests!

---

## 📊 Usage Examples

### 1. Get User's Last Access

```php
use OurEdu\RequestTracker\Facades\RequestTracker;

// Get last access time
$lastAccess = RequestTracker::getLastAccess($userUuid);

if ($lastAccess) {
    echo "Last seen: " . $lastAccess->diffForHumans(); // "5 minutes ago"
}
```

### 2. Check if User is Online

```php
if (RequestTracker::isActive($userUuid)) {
    echo "User is online! 🟢";
}
```

### 3. Get Today's Activity

```php
$today = RequestTracker::getTodayActivity($userUuid);

echo "Total requests: " . $today['access_count'];
echo "Last access: " . $today['last_access']->format('Y-m-d H:i:s');
echo "Modules visited: " . implode(', ', array_keys($today['modules_visited']));
```

### 4. Manual Tracking (For Login Events, etc.)

```php
// Track user login
RequestTracker::trackAccess(
    userUuid: $user->uuid,
    roleUuid: $user->role_id,
    module: 'authentication',
    annotation: 'User Login',
    ipAddress: $request->ip(),
    userAgent: $request->userAgent()
);
```

### 5. Track Module Access with PHP 8 Attributes

```php
use OurEdu\RequestTracker\Attributes\TrackRequest;

class StudentController extends Controller
{
    #[TrackRequest('students|Student Management')]
    public function index()
    {
        return Student::paginate();
    }
    
    #[TrackRequest('students.grades|Grade Management')]
    public function grades($id)
    {
        return Student::find($id)->grades;
    }
}
```

---

## 🎯 Artisan Commands

```bash
# Get user's journey for a specific date
php artisan tracker:user-journey {user-uuid} --date=2025-01-15

# Find who accessed a module
php artisan tracker:module-access students --from=2025-01-01 --to=2025-01-31

# Get user statistics
php artisan tracker:user-stats {user-uuid} --from=2025-01-01 --to=2025-01-31

# Cleanup old records
php artisan tracker:cleanup --days=90
```

---

## 🔍 Query Examples

### Get Users Who Accessed a Module

```php
use OurEdu\RequestTracker\Models\UserAccessDetail;

$users = UserAccessDetail::whoAccessedModule('students', 
    now()->subWeek(), 
    now()
)->get();

foreach ($users as $user) {
    echo "User: {$user->user_uuid}\n";
    echo "Total visits: {$user->total_visits}\n";
}
```

### Generate Access Report

```php
use OurEdu\RequestTracker\Models\RequestTracker;

$report = RequestTracker::whereBetween('date', ['2025-01-01', '2025-01-31'])
    ->with('accessDetails')
    ->get()
    ->map(function($tracker) {
        return [
            'user_uuid' => $tracker->user_uuid,
            'role_uuid' => $tracker->role_uuid,
            'date' => $tracker->date,
            'first_access' => $tracker->first_access,
            'last_access' => $tracker->last_access,
            'total_requests' => $tracker->access_count,
            'ip_address' => $tracker->ip_address,
            'device_type' => $tracker->device_type,
            'browser' => $tracker->browser,
            'platform' => $tracker->platform,
            'modules' => $tracker->accessDetails->groupBy('module')->map(function($items) {
                return [
                    'visits' => $items->sum('visit_count'),
                    'endpoints' => $items->count(),
                ];
            }),
        ];
    });
```

---

## 🛠️ Real-World Integration

### In Your User Model

```php
class User extends Model
{
    public function getLastAccessAttribute()
    {
        return RequestTracker::getLastAccess($this->uuid);
    }
    
    public function getIsOnlineAttribute()
    {
        return RequestTracker::isActive($this->uuid, 5);
    }
}

// Usage:
$user = User::find(1);
echo $user->last_access?->diffForHumans(); // "5 minutes ago"
echo $user->is_online ? 'Online' : 'Offline';
```

### In Your Blade Templates

```blade
<!-- Show user status -->
<div class="user-status">
    @php
        $lastAccess = RequestTracker::getLastAccess($user->uuid);
        $isOnline = RequestTracker::isActive($user->uuid);
    @endphp
    
    @if($isOnline)
        <span class="badge bg-success">🟢 Online</span>
    @else
        <span class="text-muted">
            Last seen: {{ $lastAccess?->diffForHumans() ?? 'Never' }}
        </span>
    @endif
</div>
```

### In Your Login Controller

```php
class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        
        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            
            // Track login manually
            RequestTracker::trackAccess(
                userUuid: $user->uuid,
                roleUuid: $user->role_id,
                module: 'authentication',
                annotation: 'User Login',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );
            
            return redirect()->intended('dashboard');
        }
        
        return back()->withErrors(['email' => 'Invalid credentials']);
    }
}
```

---

## 📦 What Gets Tracked?

### Automatic Tracking (Per Request)
✅ User UUID  
✅ Role UUID  
✅ Date  
✅ Request count  
✅ First access time  
✅ Last access time  
✅ IP address  
✅ User agent  
✅ Device type (mobile/desktop/tablet)  
✅ Browser (Chrome, Firefox, Safari, etc.)  
✅ Platform (Windows, iOS, Android, etc.)  
✅ Module accessed  
✅ Submodule accessed  
✅ Endpoint visited  
✅ HTTP method  
✅ Route name  
✅ Controller action  

---

## 🎓 Complete Example Project

```php
// 1. Install package
composer require our-education/laravel-request-tracker

// 2. Publish & migrate
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider"
php artisan migrate

// 3. Enable in .env
REQUEST_TRACKER_ENABLED=true

// 4. Use anywhere in your code
use OurEdu\RequestTracker\Facades\RequestTracker;

// Get last access
$lastAccess = RequestTracker::getLastAccess($userId);

// Check if online
$isOnline = RequestTracker::isActive($userId);

// Get today's activity
$today = RequestTracker::getTodayActivity($userId);

// Manual tracking
RequestTracker::trackAccess($userId, $roleId, 'dashboard', 'Dashboard Access');
```

---

## 🔧 Troubleshooting

### Package Not Working?

1. **Check if enabled:**
   ```bash
   php artisan tinker
   >>> config('request-tracker.enabled')
   # Should return: true
   ```

2. **Check migrations:**
   ```bash
   php artisan migrate:status
   # Should show: request_trackers, user_access_details
   ```

3. **Clear cache:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

### Not Tracking?

- Make sure user is authenticated
- Check if path is in exclude list
- Verify `user_sessions` table exists with `user_uuid`, `role_id`, `token`

---

## 📚 Full Documentation

See [README.md](README.md) for complete API reference and advanced usage.

---

**That's it! You're ready to track user access in any Laravel project!** 🚀
