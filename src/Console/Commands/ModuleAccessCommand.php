<?php

namespace OurEdu\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use OurEdu\RequestTracker\Models\UserAccessDetail;
use Carbon\Carbon;

class ModuleAccessCommand extends Command
{
    protected $signature = 'tracker:module-access 
                            {module : The module name to search for}
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--role= : Filter by specific role UUID}
                            {--submodule= : Filter by specific submodule}
                            {--limit=20 : Number of users to display}';

    protected $description = 'Show users who accessed a specific module within a date range';

    public function handle()
    {
        $module = $this->argument('module');
        $from = $this->option('from') ?? Carbon::now()->subWeek()->format('Y-m-d');
        $to = $this->option('to') ?? Carbon::now()->format('Y-m-d');
        $role = $this->option('role');
        $submodule = $this->option('submodule');
        $limit = $this->option('limit');

        $this->info("Module Access Report: {$module}");
        $this->info("Date Range: {$from} to {$to}");
        if ($role) {
            $this->info("Role Filter: {$role}");
        }
        if ($submodule) {
            $this->info("Submodule Filter: {$submodule}");
        }
        $this->newLine();

        $query = $submodule 
            ? UserAccessDetail::whoAccessedSubmodule($module, $submodule, $from, $to)
            : UserAccessDetail::whoAccessedModule($module, $from, $to);

        if ($role) {
            $query->where('role_uuid', $role);
        }

        $users = $query->limit($limit)->get();

        if ($users->isEmpty()) {
            $this->warn('No users found for the specified criteria.');
            return 0;
        }

        $this->table(
            ['User UUID', 'Role UUID', 'Total Visits', 'Unique Endpoints', 'First Access', 'Last Access'],
            $users->map(function ($user) {
                return [
                    $user->user_uuid,
                    $user->role_uuid,
                    $user->total_visits,
                    $user->unique_endpoints,
                    $user->first_access->format('Y-m-d H:i:s'),
                    $user->last_access->format('Y-m-d H:i:s'),
                ];
            })
        );

        $this->newLine();
        $this->info("Total Users: " . $users->count());
        $this->info("Total Visits: " . $users->sum('total_visits'));
        
        return 0;
    }
}
