# Migration Guide: From Old UserLastAccess to Laravel Request Tracker

## Issue
Your project has `UserLastAccessListener.php` that references the old package `Ouredu\UserLastAccess\Models\UserLastAccess` which no longer exists.

---

## Solution: Update Your Listener

### Option 1: Delete the Old Listener (Recommended)

The new package tracks everything automatically, so you don't need a custom listener anymore!

```bash
# In your project:
rm src/App/BaseApp/Providers/UserLastAccessListener.php

# Remove from your service provider if registered
# Edit: app/Providers/EventServiceProvider.php or similar
```

### Option 2: Update to Use New Package

If you need custom logic, update your listener to use the new package:

**Old Code (src/App/BaseApp/Providers/UserLastAccessListener.php):**
```php
use Ouredu\UserLastAccess\Models\UserLastAccess; // ❌ OLD - doesn't exist

class UserLastAccessListener
{
    public function handle($event)
    {
        UserLastAccess::track(...); // ❌ OLD
    }
}
```

**New Code:**
```php
use OurEdu\RequestTracker\Facades\RequestTracker; // ✅ NEW

class UserLastAccessListener
{
    public function handle($event)
    {
        // Track access manually
        RequestTracker::trackAccess(
            userUuid: auth()->user()->uuid,
            roleUuid: auth()->user()->role_id,
            module: 'custom_event',
            annotation: 'Custom Event Tracking',
            ipAddress: request()->ip(),
            userAgent: request()->userAgent()
        );
    }
}
```

---

## Package Error Handling ✅

**Good news!** The package now has **silent error handling** built-in.

### How It Works:

1. **By default**: All package errors are caught and logged to `storage/logs/laravel.log` without breaking your application
2. **Your app continues**: Even if the package fails, your users won't see errors

### Configuration:

```env
# .env file

# Enable tracking
REQUEST_TRACKER_ENABLED=true

# Silent errors (default: true) - Errors logged, app continues
REQUEST_TRACKER_SILENT_ERRORS=true

# Or set to false to throw errors (for debugging)
REQUEST_TRACKER_SILENT_ERRORS=false
```

### Check Logs:

```bash
# View package errors in Laravel logs
tail -f storage/logs/laravel.log | grep "Request Tracker"
```

---

## Complete Migration Steps

### Step 1: Remove Old Package References

```bash
cd /path/to/your/project

# Find all references to old package
grep -r "Ouredu\\UserLastAccess" src/
grep -r "UserLastAccess" app/

# Delete or update files that reference the old package
```

### Step 2: Install New Package

```bash
composer require our-education/laravel-request-tracker

php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="migrations"
php artisan vendor:publish --provider="OurEdu\RequestTracker\RequestTrackerServiceProvider" --tag="config"

php artisan migrate
```

### Step 3: Enable in .env

```env
REQUEST_TRACKER_ENABLED=true
REQUEST_TRACKER_SILENT_ERRORS=true
```

### Step 4: Remove Old Listener (If Exists)

```bash
# Remove the old listener file
rm src/App/BaseApp/Providers/UserLastAccessListener.php

# Or update it to use new package (see Option 2 above)
```

### Step 5: Clear Cache

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### Step 6: Test

```bash
# Make a request to your app
curl -H "Authorization: Bearer YOUR_TOKEN" http://your-app.test/api/endpoint

# Check if tracking works
php artisan tinker
>>> \OurEdu\RequestTracker\Models\RequestTracker::latest()->first()
```

---

## API Changes

### Old Package → New Package

| Old Package | New Package |
|------------|-------------|
| `Ouredu\UserLastAccess\Models\UserLastAccess` | `OurEdu\RequestTracker\Facades\RequestTracker` |
| `UserLastAccess::track()` | `RequestTracker::trackAccess()` |
| `UserLastAccess::getLastAccess()` | `RequestTracker::getLastAccess()` |
| No automatic tracking | ✅ Automatic tracking built-in |
| Manual tracking only | ✅ Both automatic & manual |

### New Features:

✅ **Automatic tracking** - No code needed  
✅ **IP address tracking**  
✅ **Device type detection** (mobile, desktop, tablet)  
✅ **Browser detection** (Chrome, Firefox, Safari, etc.)  
✅ **Platform detection** (Windows, iOS, Android, etc.)  
✅ **Module & endpoint tracking**  
✅ **PHP 8 Attributes** support  
✅ **Artisan commands** for reports  
✅ **Silent error handling** ← NEW!  

---

## Troubleshooting

### Error: "Class not found"

```bash
# Clear autoload cache
composer dump-autoload

# Clear Laravel cache
php artisan config:clear
php artisan cache:clear
```

### Error: "Table doesn't exist"

```bash
# Run migrations
php artisan migrate

# Check migrations status
php artisan migrate:status
```

### Package errors breaking your app?

```env
# Enable silent errors (should be default)
REQUEST_TRACKER_SILENT_ERRORS=true
```

```bash
# Check logs for package errors
tail -f storage/logs/laravel.log | grep "Request Tracker"
```

---

## Questions?

- See [INSTALLATION.md](INSTALLATION.md) for full setup guide
- See [README.md](README.md) for complete API reference
- Check logs: `storage/logs/laravel.log`

**You're all set!** 🚀
