<?php

namespace OurEdu\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use OurEdu\RequestTracker\Models\RequestTracker;
use OurEdu\RequestTracker\Models\AccessLog;
use Carbon\Carbon;

class CleanupLogsCommand extends Command
{
    protected $signature = 'tracker:cleanup 
                            {--days=30 : Delete records older than this many days}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Cleanup old access tracking records';

    public function handle()
    {
        $days = $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("🧹 Cleaning up access tracking records older than {$days} days ({$cutoffDate->format('Y-m-d')})");
        $this->newLine();

        // Count records to be deleted
        $trackersCount = RequestTracker::where('date', '<', $cutoffDate->format('Y-m-d'))->count();
        $logsCount = AccessLog::where('created_at', '<', $cutoffDate)->count();

        if ($trackersCount === 0 && $logsCount === 0) {
            $this->info('✅ No records found to clean up.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Table', 'Records to Delete'],
            [
                ['request_trackers', $trackersCount],
                ['access_logs', $logsCount],
                ['Total', $trackersCount + $logsCount],
            ]
        );

        if ($dryRun) {
            $this->warn('🔍 Dry run mode - No records were actually deleted.');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to proceed with deletion?')) {
            $this->info('Cancelled.');
            return Command::FAILURE;
        }

        // Delete old records
        $this->info('Deleting old records...');
        
        $deletedTrackers = RequestTracker::where('date', '<', $cutoffDate->format('Y-m-d'))->delete();
        $deletedLogs = AccessLog::where('created_at', '<', $cutoffDate)->delete();

        $this->newLine();
        $this->info("✅ Cleanup completed!");
        $this->line("   - Deleted {$deletedTrackers} tracker records");
        $this->line("   - Deleted {$deletedLogs} access log records");

        return Command::SUCCESS;
    }
}
