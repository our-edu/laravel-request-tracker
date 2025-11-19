
# Laravel Request Tracker

A drop-in Laravel package that records a detailed trace for incoming HTTP requests (start → finish) and saves it to the database — **no per-route middleware edits required**.  
Default behavior is **event-driven** (creates a trace at `RouteMatched` and updates it at `RequestHandled`). Optionally you can enable a terminable middleware (auto-registered) for lower-level timing — still without editing your app's `Kernel`.

> This README assumes package namespace `YourVendor\RequestTracker` and composer name `your-vendor/laravel-request-tracker`. Replace these with your vendor/name as you publish.

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Publish & Migrate](#publish--migrate)
- [Files & structure](#files--structure-suggested-package-layout)

---

## Features

- Tracks every matched request with: method, path, route name, controller action, IP, user agent, payload (optional), timestamps, duration (ms), response status, `user_id` (if resolvable), and arbitrary `meta`.
- Event-driven by default — no modification to middleware groups or route files.
- Optional terminable middleware that can be **auto-pushed** into the `api` group programmatically by the ServiceProvider (no manual Kernel edits).
- Config-driven: enable/disable, blacklist/whitelist payload keys, route filters, guards to resolve users, and extra meta.
- Small facade/API allowing manual steps/attachments to the current trace (useful to record domain-level steps like authorization, external calls, errors).
- Publishable migration and config for easy installation.

---

## Requirements

- PHP >= 8.0
- Laravel 9, 10 or 11
- A database (MySQL, Postgres, etc.) for storing traces

---

## Installation

### 1) Via Composer (Packagist)
```bash
composer require our-education/laravel-request-tracker
```

Because the package registers a provider using Laravel auto-discovery, you normally **don't** need to add the provider to `config/app.php`.

### 2) Local development (path repository)
If you're working locally:
1. In your app `composer.json` add:
```json
"repositories": [
  {
    "type": "path",
    "url": "https://github.com/our-edu/laravel-request-tracker"
  }
]
```
2. Require it:
```bash
composer require our-edu/laravel-request-tracker
```

---

## Publish & Migrate

Publish the package config file and migration to your application:

```bash
php artisan vendor:publish --provider="OurEducation\RequestTracker\RequestTrackerServiceProvider" --tag="config"
php artisan vendor:publish --provider="OurEducation\RequestTracker\RequestTrackerServiceProvider" --tag="migrations"
```

Run migrations:

```bash
php artisan migrate
```

---
## Files & structure (suggested package layout)

```
laravel-request-tracker/
├─ composer.json
├─ README.md
├─ LICENSE
├─ config/
│  └─ request-tracker.php
├─ database/
│  └─ migrations/2025_01_01_000000_create_request_trackers_table.php
├─ src/
│  ├─ RequestTrackerServiceProvider.php
│  ├─ Models/
│  │  └─ RequestTracker.php
│  ├─ Listeners/
   └─ EventsSubscriber.php