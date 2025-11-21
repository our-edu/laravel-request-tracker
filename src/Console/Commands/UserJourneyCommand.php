<?php

namespace OurEdu\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\UserAccessDetail;

class UserJourneyCommand extends Command
{
    protected $signature = 'tracker:user-journey 
                            {user_uuid : The user UUID to track}
                            {--date= : Specific date (Y-m-d format)}
                            {--role= : Filter by role UUID}
                            {--module= : Filter by module name}';

    protected $description = 'View detailed user journey - what endpoints and modules a user visited';

    public function handle()
    {
        $userUuid = $this->argument('user_uuid');
        $date = $this->option('date') ?? now()->format('Y-m-d');
        $roleUuid = $this->option('role');
        $module = $this->option('module');

        $this->info("🗺️  User Journey for: {$userUuid}");
        $this->info("📅 Date: {$date}");
        $this->newLine();

        // Get daily summary
        $tracker = RequestTracker::forUser($userUuid)->forDate($date);
        if ($roleUuid) {
            $tracker->where('role_uuid', $roleUuid);
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
                ['Role UUID', $tracker->role_uuid ? substr($tracker->role_uuid, 0, 20) . '...' : 'N/A'],
                ['Total Requests', $tracker->access_count],
                ['First Access', $tracker->first_access->format('H:i:s')],
                ['Last Access', $tracker->last_access->format('H:i:s')],
                ['Application', $tracker->application ?? 'N/A'],
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
            ['Time', 'Module', 'Submodule', 'Endpoint', 'Visits', 'Annotation'],
            $details->map(function ($detail) {
                return [
                    $detail->first_visit->format('H:i:s'),
                    $detail->module ?? 'N/A',
                    $detail->submodule ?? '-',
                    strlen($detail->endpoint) > 40 ? substr($detail->endpoint, 0, 37) . '...' : $detail->endpoint,
                    $detail->visit_count,
                    $detail->annotation ?? '-',
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
}
