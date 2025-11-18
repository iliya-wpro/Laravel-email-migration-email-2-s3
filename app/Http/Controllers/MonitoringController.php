<?php

namespace App\Http\Controllers;

use App\Models\MigrationProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitoringController
{
    public function dashboard(): View
    {
        try {
            $stats = $this->gatherStats();
        } catch (\Exception $e) {
            $stats = $this->getEmptyStats($e->getMessage());
        }

        return view('monitoring.dashboard', compact('stats'));
    }

    private function getEmptyStats(string $error = ''): array
    {
        return [
            'queue' => [
                'pending' => 0,
                'failed' => 0,
                'oldest_job_age' => 0,
                'queue_health' => 'unknown',
            ],
            'migration' => [
                'status' => 'Database not available',
                'total_emails' => 0,
                'processed' => 0,
                'failed' => 0,
                'success_rate' => 0,
                'started_at' => null,
                'completed_at' => null,
            ],
            'system' => $this->getSystemStats(),
            'recent_errors' => [],
            'performance' => [
                'emails_per_minute' => 0,
                'estimated_completion' => null,
                'elapsed_time' => 0,
            ],
            'workers' => [
                'configured' => (int) env('QUEUE_WORKERS', 10),
                'active' => 0,
                'idle' => (int) env('QUEUE_WORKERS', 10),
                'throughput_per_worker' => 0,
                'avg_jobs_per_worker' => 0,
                'utilization' => 0,
            ],
            'error' => $error,
        ];
    }

    private function gatherStats(): array
    {
        return [
            'queue' => $this->getQueueStats(),
            'migration' => $this->getMigrationStats(),
            'system' => $this->getSystemStats(),
            'recent_errors' => $this->getRecentErrors(),
            'performance' => $this->getPerformanceStats(),
            'workers' => $this->getWorkerStats(),
        ];
    }

    private function getQueueStats(): array
    {
        $queueDriver = config('queue.default');

        if ($queueDriver === 'redis') {
            return $this->getRedisQueueStats();
        }

        return $this->getDatabaseQueueStats();
    }

    private function getRedisQueueStats(): array
    {
        try {
            $queueName = 'queues:email-migration';

            // Get pending jobs count from Redis
            $pending = \Illuminate\Support\Facades\Redis::llen($queueName);

            // Get failed jobs from database (failed jobs still go to DB)
            $failed = DB::table('failed_jobs')->count();

            // Calculate remaining from emails table
            $totalEmails = DB::table('emails')->count();
            $processed = DB::table('emails')->where('is_migrated', true)->count();
            $remaining = max(0, $totalEmails - $processed - $failed);

            return [
                'pending' => max($pending, $remaining), // Show remaining from emails table
                'failed' => $failed,
                'oldest_job_age' => 0, // Redis doesn't track job age easily
                'queue_health' => $this->determineQueueHealth($pending, $failed),
            ];
        } catch (\Exception $e) {
            return $this->getDatabaseQueueStats();
        }
    }

    private function getDatabaseQueueStats(): array
    {
        // Use count() instead of get() to avoid loading all jobs into memory
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        // Get only the oldest job's created_at timestamp
        $oldestJob = DB::table('jobs')
            ->orderBy('created_at', 'asc')
            ->first(['created_at']);

        $oldestJobAge = 0;
        if ($oldestJob && $oldestJob->created_at) {
            // created_at is a UNIX timestamp, convert it properly
            $oldestJobTime = \Carbon\Carbon::createFromTimestamp($oldestJob->created_at);
            // Calculate the absolute difference in minutes
            $oldestJobAge = abs($oldestJobTime->diffInMinutes(now(), false));
        }

        return [
            'pending' => $pending,
            'failed' => $failed,
            'oldest_job_age' => round($oldestJobAge, 2),
            'queue_health' => $this->determineQueueHealth($pending, $failed),
        ];
    }

    private function getMigrationStats(): array
    {
        // Calculate stats directly from emails table (works with any queue driver)
        $totalEmails = DB::table('emails')->count();
        $processed = DB::table('emails')->where('is_migrated', true)->count();
        $failed = DB::table('failed_jobs')->count();
        $pending = max(0, $totalEmails - $processed - $failed);

        // Determine status
        $status = 'pending';
        if ($pending === 0 && $totalEmails > 0) {
            $status = 'completed';
        } elseif ($processed > 0) {
            $status = 'processing';
        }

        // Calculate success rate
        $totalAttempted = $processed + $failed;
        $successRate = $totalAttempted > 0
            ? round(($processed / $totalAttempted) * 100, 2)
            : 0;

        // Get last error from failed_jobs
        $lastError = null;
        $lastFailedJob = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->first();
        if ($lastFailedJob) {
            $lastError = substr($lastFailedJob->exception, 0, 200);
        }

        return [
            'status' => $status,
            'total_emails' => $totalEmails,
            'processed' => $processed,
            'failed' => $failed,
            'success_rate' => $successRate,
            'started_at' => null,
            'completed_at' => $status === 'completed' ? now()->format('Y-m-d H:i:s') : null,
            'last_error' => $lastError,
        ];
    }

    private function getSystemStats(): array
    {
        return [
            'db_connection' => $this->checkDatabaseConnection(),
            'storage_writable' => is_writable(storage_path('logs')),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'uptime' => $this->getServerUptime(),
        ];
    }

    private function getRecentErrors(): array
    {
        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(10)
            ->get();

        return $failedJobs->map(function ($job) {
            $payload = json_decode($job->payload, true);
            return [
                'id' => $job->id,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'exception' => \Illuminate\Support\Str::limit($job->exception, 200),
            ];
        })->toArray();
    }

    private function getPerformanceStats(): array
    {
        // Calculate stats from emails table
        $totalEmails = DB::table('emails')->count();
        $processed = DB::table('emails')->where('is_migrated', true)->count();
        $failed = DB::table('failed_jobs')->count();
        $pending = max(0, $totalEmails - $processed - $failed);

        if ($processed === 0) {
            return [
                'emails_per_minute' => 0,
                'estimated_completion' => null,
                'elapsed_time' => 0,
            ];
        }

        // Get the oldest processed email to estimate start time
        $oldestProcessedEmail = DB::table('emails')
            ->where('is_migrated', true)
            ->orderBy('migration_attempted_at', 'asc')
            ->first(['migration_attempted_at']);

        if (!$oldestProcessedEmail || !$oldestProcessedEmail->migration_attempted_at) {
            return [
                'emails_per_minute' => 0,
                'estimated_completion' => null,
                'elapsed_time' => 0,
            ];
        }

        $startTime = \Carbon\Carbon::parse($oldestProcessedEmail->migration_attempted_at);
        $elapsedMinutes = abs($startTime->diffInMinutes(now(), false));

        $emailsPerMinute = $elapsedMinutes > 0
            ? round($processed / $elapsedMinutes, 2)
            : 0;

        $estimatedMinutes = $emailsPerMinute > 0
            ? round($pending / $emailsPerMinute)
            : null;

        return [
            'emails_per_minute' => $emailsPerMinute,
            'estimated_completion' => $estimatedMinutes,
            'elapsed_time' => round($elapsedMinutes, 2),
        ];
    }

    private function checkDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function determineQueueHealth(int $pending, int $failed): string
    {
        if ($failed > 10) {
            return 'critical';
        }
        if ($failed > 0 || $pending > 1000) {
            return 'warning';
        }
        return 'healthy';
    }

    private function getWorkerStats(): array
    {
        // Get configured number of workers from environment
        $configuredWorkers = (int) env('QUEUE_WORKERS', 10);
        $workerTimeout = (int) env('QUEUE_WORKER_TIMEOUT', 300);

        // Count jobs actively being processed (reserved within timeout window)
        // Only count jobs reserved in the last [timeout + 60s buffer] to exclude stale reservations
        $timeoutThreshold = now()->subSeconds($workerTimeout + 60)->timestamp;

        $activeJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>', $timeoutThreshold)
            ->count();

        // Cap active jobs at configured workers (can't have more active than configured)
        $activeJobs = min($activeJobs, $configuredWorkers);

        // Calculate throughput from emails table
        $totalEmails = DB::table('emails')->count();
        $processed = DB::table('emails')->where('is_migrated', true)->count();
        $failed = DB::table('failed_jobs')->count();

        $throughputPerWorker = 0;
        $avgJobsPerWorker = 0;

        if ($processed > 0) {
            // Get the oldest processed email to estimate start time
            $oldestProcessedEmail = DB::table('emails')
                ->where('is_migrated', true)
                ->orderBy('migration_attempted_at', 'asc')
                ->first(['migration_attempted_at']);

            if ($oldestProcessedEmail && $oldestProcessedEmail->migration_attempted_at) {
                $startTime = \Carbon\Carbon::parse($oldestProcessedEmail->migration_attempted_at);
                $elapsedMinutes = abs($startTime->diffInMinutes(now(), false));

                if ($elapsedMinutes > 0 && $configuredWorkers > 0) {
                    // Calculate emails processed per worker per minute
                    $totalEmailsPerMinute = $processed / $elapsedMinutes;
                    $throughputPerWorker = round($totalEmailsPerMinute / $configuredWorkers, 2);

                    // Calculate average jobs processed per worker
                    $avgJobsPerWorker = round($processed / $configuredWorkers, 0);
                }
            }
        }

        return [
            'configured' => $configuredWorkers,
            'active' => $activeJobs,
            'idle' => max(0, $configuredWorkers - $activeJobs),
            'throughput_per_worker' => $throughputPerWorker,
            'avg_jobs_per_worker' => $avgJobsPerWorker,
            'utilization' => $configuredWorkers > 0
                ? round(($activeJobs / $configuredWorkers) * 100, 1)
                : 0,
        ];
    }

    private function getServerUptime(): string
    {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $seconds = (int) explode(' ', $uptime)[0];
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return "{$days}d {$hours}h {$minutes}m";
        }
        return 'N/A';
    }
}
