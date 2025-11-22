<?php

namespace OurEdu\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\UserAccessDetail;

class UserJourneyCommand extends Command
{
    protected $signature = 'tracker:user-journey 
                            {national_id? : The user national ID to track (optional for general report)}
                            {--date= : Specific date (Y-m-d format)}
                            {--role= : Filter by role name}
                            {--module= : Filter by module name}
                            {--limit=50 : Number of journeys to show for general report}';

    protected $description = 'View detailed user journey by national ID - what endpoints and modules users visited (general or filtered by role)';

    public function handle()
    {
        $nationalId = $this->argument('national_id');
        $date = $this->option('date') ?? now()->format('Y-m-d');
        $module = $this->option('module');
        
        // Interactive role selection
        $roleName = $this->option('role');
        if (!$roleName && $this->confirm('Do you want to filter by a specific role?', false)) {
            $roleName = $this->ask('Enter the role name');
        }
            
        if ($nationalId) {
            // Resolve user UUID from national ID
            $user = DB::table('users')->where('national_id', $nationalId)->first();
            if (!$user) {
                $this->error("User not found with national ID: {$nationalId}");
                return Command::FAILURE;
            }
            return $this->showUserJourney($user->uuid, $nationalId, $date, $roleName, $module);
        } else {
            return $this->showGeneralJourney($date, $roleName, $module);
        }
    }
    
    protected function showUserJourney($userUuid, $nationalId, $date, $roleName, $module)
    {

        $this->info("🗺️  User Journey for National ID: {$nationalId}");
        $this->info("📅 Date: {$date}");
        if ($roleName) {
            $this->info("🎭 Role Filter: {$roleName}");
        } else {
            $this->comment("📋 All roles");
        }
        $this->newLine();

        // Get daily summary
        $tracker = RequestTracker::forUser($userUuid)->forDate($date);
        if ($roleName) {
            $tracker->where('role_name', $roleName);
        }
        $tracker = $tracker->first();

        if (!$tracker) {
            $this->warn('No activity found for this user on this date.');
            return Command::FAILURE;
        }

        // Display daily summary
        $this->line('📊 Daily Summary:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['User UUID', substr($tracker->user_uuid, 0, 20) . '...'],
                ['Role Name', $tracker->role_name ? substr($tracker->role_name, 0, 20) . '...' : 'N/A'],
                ['Total Requests', $tracker->access_count],
                ['First Access', $tracker->first_access->format('H:i:s')],
                ['Last Access', $tracker->last_access->format('H:i:s')],
            ]
        );

        $this->newLine();

        // Get detailed endpoint visits
        $details = UserAccessDetail::where('tracker_uuid', $tracker->uuid);
        if ($module) {
            $details->where('module', $module);
        }
        $details = $details->orderBy('first_visit')->get();

        if ($details->isEmpty()) {
            $this->warn('No detailed endpoint visits found.');
            return Command::SUCCESS;
        }

        // Group by module
        $byModule = $details->groupBy('module');

        $this->line('🎯 Modules Accessed:');
        $modulesSummary = $byModule->map(function ($items, $module) {
            return [
                'module' => $module ?? 'N/A',
                'unique_endpoints' => $items->count(),
                'total_visits' => $items->sum('visit_count'),
            ];
        })->values();

        $this->table(
            ['Module', 'Unique Endpoints', 'Total Visits'],
            $modulesSummary->map(function ($m) {
                return [$m['module'], $m['unique_endpoints'], $m['total_visits']];
            })
        );

        $this->newLine();

        // Detailed endpoint list
        $this->line('📍 Detailed Endpoint Visits:');
        $this->table(
            ['Time', 'Module', 'Submodule', 'Endpoint', 'Visits', 'Action'],
            $details->map(function ($detail) {
                return [
                    $detail->first_visit->format('H:i:s'),
                    $detail->module ?? 'N/A',
                    $detail->submodule ?? '-',
                    strlen($detail->endpoint) > 40 ? substr($detail->endpoint, 0, 37) . '...' : $detail->endpoint,
                    $detail->visit_count,
                    $detail->action ?? '-',
                ];
            })->toArray()
        );

        // Show module breakdown
        if ($byModule->count() > 1) {
            $this->newLine();
            $this->line('📈 Module Breakdown:');
            
            foreach ($byModule as $moduleName => $items) {
                $this->newLine();
                $this->comment("Module: " . ($moduleName ?? 'Unknown'));
                
                $this->table(
                    ['Submodule', 'Endpoint', 'Visits', 'First Visit', 'Last Visit'],
                    $items->map(function ($item) {
                        return [
                            $item->submodule ?? '-',
                            strlen($item->endpoint) > 35 ? substr($item->endpoint, 0, 32) . '...' : $item->endpoint,
                            $item->visit_count,
                            $item->first_visit->format('H:i:s'),
                            $item->last_visit->format('H:i:s'),
                        ];
                    })->toArray()
                );
            }
        }

        return Command::SUCCESS;
    }
    
    protected function showGeneralJourney($date, $roleName, $module)
    {
        $this->info("🗺️  General User Journey Report");
        $this->info("📅 Date: {$date}");
        if ($roleName) {
            $this->info("🎭 Role Filter: {$roleName}");
        } else {
            $this->comment("📋 All roles");
        }
        if ($module) {
            $this->info("📦 Module Filter: {$module}");
        }
        $this->newLine();

        // Get all trackers for the date
        $trackersQuery = RequestTracker::forDate($date);
        if ($roleName) {
            $trackersQuery->where('role_name', $roleName);
        }
        
        $trackers = $trackersQuery->get();

        if ($trackers->isEmpty()) {
            $this->warn('No activity found for this date.');
            return Command::FAILURE;
        }

        // Summary statistics
        $this->line('📊 General Summary:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Users', $trackers->unique('user_uuid')->count()],
                ['Total Requests', $trackers->sum('access_count')],
                ['Unique Roles', $trackers->whereNotNull('role_name')->unique('role_name')->count()],
                ['Total Sessions', $trackers->whereNotNull('user_session_uuid')->unique('user_session_uuid')->count()],
            ]
        );

        $this->newLine();

        // Get detailed access information
        $detailsQuery = UserAccessDetail::whereIn('tracker_uuid', $trackers->pluck('uuid'));
        if ($module) {
            $detailsQuery->where('module', $module);
        }
        $details = $detailsQuery->get();

        if ($details->isEmpty()) {
            $this->warn('No detailed endpoint visits found.');
            return Command::SUCCESS;
        }

        // Module usage statistics
        $this->line('📦 Module Usage:');
        $moduleStats = $details->groupBy('module')->map(function ($items, $module) {
            return [
                'module' => $module ?? 'Unknown',
                'unique_users' => $items->unique('user_uuid')->count(),
                'total_visits' => $items->sum('visit_count'),
                'unique_endpoints' => $items->count(),
            ];
        })->sortByDesc('total_visits')->values();

        $this->table(
            ['Module', 'Unique Users', 'Total Visits', 'Unique Endpoints'],
            $moduleStats->map(function ($stat) {
                return [
                    $stat['module'],
                    $stat['unique_users'],
                    $stat['total_visits'],
                    $stat['unique_endpoints'],
                ];
            })->toArray()
        );

        $this->newLine();

        // Top accessed endpoints
        $this->line('🔥 Top 10 Most Accessed Endpoints:');
        $topEndpoints = $details->sortByDesc('visit_count')->take(10);
        
        $this->table(
            ['Module', 'Submodule', 'Endpoint', 'Total Visits', 'Unique Users'],
            $topEndpoints->map(function ($detail) use ($details) {
                $usersCount = $details->where('endpoint', $detail->endpoint)
                    ->where('module', $detail->module)
                    ->unique('user_uuid')
                    ->count();
                    
                return [
                    $detail->module ?? 'N/A',
                    $detail->submodule ?? '-',
                    strlen($detail->endpoint) > 35 ? substr($detail->endpoint, 0, 32) . '...' : $detail->endpoint,
                    $detail->visit_count,
                    $usersCount,
                ];
            })->toArray()
        );

        // Role breakdown if not filtered by role
        if (!$roleName) {
            $this->newLine();
            $this->line('🎭 Activity by Role:');
            
            $roleStats = $trackers->whereNotNull('role_name')
                ->groupBy('role_name')
                ->map(function ($items, $roleName) {
                    return [
                        'role_name' => $roleName,
                        'users' => $items->unique('user_uuid')->count(),
                        'requests' => $items->sum('access_count'),
                    ];
                })
                ->sortByDesc('requests')
                ->values();

            $this->table(
                ['Role Name', 'Unique Users', 'Total Requests'],
                $roleStats->map(function ($stat) {
                    return [
                        substr($stat['role_name'], 0, 20) . '...',
                        $stat['users'],
                        $stat['requests'],
                    ];
                })->toArray()
            );
        }

        return Command::SUCCESS;
    }
}
