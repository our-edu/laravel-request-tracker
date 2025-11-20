<?php

namespace OurEdu\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\AccessLog;

class UserAccessStatsCommand extends Command
{
    protected $signature = 'tracker:user-stats 
                            {user_uuid? : The user UUID to get stats for}
                            {--date= : Specific date (Y-m-d format)}
                            {--from= : Start date for range (Y-m-d format)}
                            {--to= : End date for range (Y-m-d format)}
                            {--top=10 : Number of top results to show}';

    protected $description = 'Display user access statistics';

    public function handle()
    {
        $userUuid = $this->argument('user_uuid');
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        $top = $this->option('top');

        if ($userUuid) {
            $this->showUserStats($userUuid, $date, $from, $to);
        } else {
            $this->showOverallStats($date, $from, $to, $top);
        }

        return Command::SUCCESS;
    }

    protected function showUserStats($userUuid, $date, $from, $to)
    {
        $this->info("📊 Access Statistics for User: {$userUuid}");
        $this->newLine();

        $query = RequestTracker::forUser($userUuid);

        if ($date) {
            $query->forDate($date);
            $this->line("Date: {$date}");
        } elseif ($from && $to) {
            $query->forDateRange($from, $to);
            $this->line("Period: {$from} to {$to}");
        } else {
            $query->thisMonth();
            $this->line("Period: This Month");
        }

        $stats = $query->get();

        if ($stats->isEmpty()) {
            $this->warn('No access records found for this user.');
            return;
        }

        // Summary statistics
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Days Active', $stats->count()],
                ['Total Requests', $stats->sum('access_count')],
                ['Avg Requests/Day', round($stats->avg('access_count'), 2)],
                ['Last Access', $stats->max('last_access')],
            ]
        );

        $this->newLine();

        // Daily breakdown
        $this->line('📅 Daily Access Breakdown:');
        $this->table(
            ['Date', 'Access Count', 'Routes', 'Last Access'],
            $stats->map(function ($record) {
                return [
                    $record->date,
                    $record->access_count,
                    $record->route_name ?? 'N/A',
                    $record->last_access->format('H:i:s'),
                ];
            })->toArray()
        );
    }

    protected function showOverallStats($date, $from, $to, $top)
    {
        $this->info("📊 Overall Access Statistics");
        $this->newLine();

        $query = RequestTracker::query();

        if ($date) {
            $query->forDate($date);
            $this->line("Date: {$date}");
        } elseif ($from && $to) {
            $query->forDateRange($from, $to);
            $this->line("Period: {$from} to {$to}");
        } else {
            $query->today();
            $this->line("Period: Today");
        }

        $stats = $query->get();

        if ($stats->isEmpty()) {
            $this->warn('No access records found.');
            return;
        }

        // Summary
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Users', $stats->unique('user_uuid')->count()],
                ['Total Requests', $stats->sum('access_count')],
                ['Unique Routes', $stats->whereNotNull('route_name')->unique('route_name')->count()],
                ['Avg Response Time (ms)', round($stats->avg('response_time'), 2)],
            ]
        );

        $this->newLine();

        // Top users
        $this->line("👥 Top {$top} Most Active Users:");
        $topUsers = $stats->groupBy('user_uuid')
            ->map(function ($records) {
                return [
                    'user_uuid' => $records->first()->user_uuid,
                    'total_requests' => $records->sum('access_count'),
                    'days_active' => $records->count(),
                ];
            })
            ->sortByDesc('total_requests')
            ->take($top);

        $this->table(
            ['User UUID', 'Total Requests', 'Days Active'],
            $topUsers->map(function ($user) {
                return [
                    substr($user['user_uuid'], 0, 13) . '...',
                    $user['total_requests'],
                    $user['days_active'],
                ];
            })->values()->toArray()
        );

        $this->newLine();

        // Top routes
        $this->line("🔥 Top {$top} Most Accessed Routes:");
        $topRoutes = $stats->whereNotNull('route_name')
            ->groupBy('route_name')
            ->map(function ($records) {
                return [
                    'route' => $records->first()->route_name,
                    'total_requests' => $records->sum('access_count'),
                ];
            })
            ->sortByDesc('total_requests')
            ->take($top);

        if ($topRoutes->isNotEmpty()) {
            $this->table(
                ['Route Name', 'Total Requests'],
                $topRoutes->map(function ($route) {
                    return [$route['route'], $route['total_requests']];
                })->values()->toArray()
            );
        }
    }
}
