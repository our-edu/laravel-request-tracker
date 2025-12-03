<?php

namespace OurEdu\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use OurEdu\RequestTracker\Jobs\TrackUserAccessJob;

class TestTrackingCommand extends Command
{
    protected $signature = 'tracker:test';
    protected $description = 'Test request tracker setup and configuration';

    public function handle()
    {
        $this->info('🔍 Testing Request Tracker Setup...');
        $this->newLine();

        // 1. Check if enabled
        $enabled = config('request-tracker.enabled');
        $this->checkStatus('Tracker Enabled', $enabled, $enabled ? 'Yes' : 'No');

        // 2. Check queue config
        $queueName = config('request-tracker.queue.queue');
        $this->info("Queue Name: " . ($queueName ?: 'default'));

        // 3. Check Redis connection
        try {
            Redis::ping();
            $this->checkStatus('Redis Connection', true, 'Connected');
        } catch (\Exception $e) {
            $this->checkStatus('Redis Connection', false, $e->getMessage());
            return Command::FAILURE;
        }

        // 4. Check queue length
        try {
            $queueKey = 'queues:' . ($queueName ?: 'default');
            $queueLength = Redis::llen($queueKey);
            $this->info("Jobs in Queue ($queueKey): $queueLength");
        } catch (\Exception $e) {
            $this->error("Error checking queue: " . $e->getMessage());
        }

        // 5. Check failed jobs
        try {
            $failedCount = DB::table('failed_jobs')->count();
            $this->info("Failed Jobs: $failedCount");
            
            if ($failedCount > 0) {
                $this->warn("⚠️  You have failed jobs!");
                $latest = DB::table('failed_jobs')->latest('failed_at')->first();
                if ($latest) {
                    $this->error("Latest error: " . substr($latest->exception, 0, 200));
                }
            }
        } catch (\Exception $e) {
            $this->warn("Could not check failed jobs: " . $e->getMessage());
        }

        // 6. Check database tables
        $this->newLine();
        $this->info('📊 Checking Database Tables:');
        try {
            $trackersCount = DB::table('request_trackers')->count();
            $this->checkStatus('request_trackers table', true, "$trackersCount records");
        } catch (\Exception $e) {
            $this->checkStatus('request_trackers table', false, $e->getMessage());
        }

        try {
            $detailsCount = DB::table('user_access_details')->count();
            $this->checkStatus('user_access_details table', true, "$detailsCount records");
        } catch (\Exception $e) {
            $this->checkStatus('user_access_details table', false, $e->getMessage());
        }

        // 7. Test job dispatch
        $this->newLine();
        if ($this->confirm('Do you want to dispatch a test job?', true)) {
            try {
                $job = new TrackUserAccessJob(
                    'test-user-uuid',
                    'test-role-uuid',
                    'test-role',
                    'test-session-uuid',
                    now()->format('Y-m-d'),
                    'test.route',
                    'TestController@test',
                    'GET',
                    'api/v1/en/test',
                    config('request-tracker')
                );

                dispatch($job);
                $this->info('✅ Test job dispatched successfully!');
                
                sleep(1);
                $newQueueLength = Redis::llen($queueKey);
                $this->info("Queue length after dispatch: $newQueueLength");
                
                if ($newQueueLength > $queueLength) {
                    $this->info('✅ Job was added to queue!');
                } else {
                    $this->warn('⚠️  Queue length did not increase. Job might have been processed immediately or failed.');
                }
            } catch (\Exception $e) {
                $this->error('❌ Error dispatching job: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('📝 Recommendations:');
        
        if (!$enabled) {
            $this->warn('- Set REQUEST_TRACKER_ENABLED=true in .env');
        }
        
        if ($queueLength > 10) {
            $this->warn("- You have $queueLength jobs waiting. Make sure queue worker is running:");
            $this->line("  php artisan queue:work redis --queue=" . ($queueName ?: 'default'));
        }

        return Command::SUCCESS;
    }

    protected function checkStatus(string $label, bool $status, string $message)
    {
        $icon = $status ? '✅' : '❌';
        $this->line("$icon $label: $message");
    }
}
