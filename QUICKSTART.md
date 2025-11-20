# Quick Start - Get User Last Access

The simplest way to use this package after installation:

## Installation

```bash
composer require our-education/laravel-request-tracker
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="migrations"
php artisan migrate
```

## Enable Tracking

```env
REQUEST_TRACKER_ENABLED=true
```

---

## Usage - Two Ways

### 1️⃣ **Automatic Tracking** (Recommended)

Once enabled, the package **automatically tracks all authenticated requests**. No code needed!

### 2️⃣ **Manual Tracking** (For Custom Events)

Track user access manually for login events, scheduled tasks, or custom scenarios.

---

## Manual Tracking Examples

### Track User Login

```php
use OurEdu\RequestTracker\Facades\RequestTracker;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Your authentication logic
        $user = Auth::user();
        $roleUuid = $user->current_role_id;
        
        // Track that the user logged in
        RequestTracker::trackAccess(
            userUuid: $user->uuid,
            roleUuid: $roleUuid,
            module: 'authentication',
            annotation: 'User Login'
        );
        
        return redirect()->route('dashboard');
    }
}
```

### Track Specific Module Access

```php
// المدرس فتح موديول الطلاب
RequestTracker::trackModuleAccess(
    userUuid: $teacherUuid,
    roleUuid: $teacherRoleUuid,
    module: 'students',
    annotation: 'تصفح قائمة الطلاب'
);

// المدرس دخل على grades submodule
RequestTracker::trackModuleAccess(
    userUuid: $teacherUuid,
    roleUuid: $teacherRoleUuid,
    module: 'students',
    submodule: 'grades',
    annotation: 'إدارة درجات الطلاب'
);
```

### Track Custom Events

```php
// عند فتح التطبيق من الموبايل
RequestTracker::trackAccess(
    userUuid: $user->uuid,
    roleUuid: $roleUuid,
    module: 'mobile_app',
    annotation: 'Mobile App Launch'
);

// عند عمل scheduled task
RequestTracker::trackAccess(
    userUuid: 'system',
    roleUuid: 'system-role',
    module: 'cron_jobs',
    annotation: 'Daily Report Generation'
);
```

---

## Get User's Last Access Time

```php
use OurEdu\RequestTracker\Facades\RequestTracker;

// Get user's last access - works with both automatic AND manual tracking
$lastAccess = RequestTracker::getLastAccess($userUuid);
$lastAccess = RequestTracker::getLastAccess($userUuid);

if ($lastAccess) {
    echo "Last seen: " . $lastAccess->diffForHumans(); // "5 minutes ago"
    echo "Exact time: " . $lastAccess->format('Y-m-d H:i:s');
}
```

## 3. Check if User is Online

```php
if (RequestTracker::isActive($userUuid)) {
    echo "🟢 User is online!";
} else {
    echo "⚪ User is offline";
}
```

## 4. Get Today's Activity

```php
$today = RequestTracker::getTodayActivity($userUuid);

echo "Requests today: " . $today['access_count'];
echo "Last seen: " . $today['last_access']->diffForHumans();
echo "Modules visited: " . implode(', ', array_keys($today['modules_visited']));
```

## Real-World Example

```php
class UserController extends Controller
{
    public function showProfile($id)
    {
        $user = User::findOrFail($id);
        
        // Get last access time
        $lastAccess = RequestTracker::getLastAccess($user->uuid);
        $isOnline = RequestTracker::isActive($user->uuid, 5);
        
        return view('user.profile', [
            'user' => $user,
            'last_seen' => $lastAccess?->diffForHumans() ?? 'Never',
            'is_online' => $isOnline,
        ]);
    }
}
```

```blade
<!-- In your Blade template -->
<div class="user-status">
    @if($is_online)
        <span class="badge bg-success">🟢 Online</span>
    @else
        <span class="text-muted">Last seen: {{ $last_seen }}</span>
    @endif
</div>
```

That's all you need! 🚀
