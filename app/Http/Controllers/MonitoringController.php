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
        $progress = MigrationProgress::latest()->first();

        if (!$progress) {
            return [
                'status' => 'No migration started',
                'total_emails' => 0,
                'processed' => 0,
                'failed' => 0,
                'success_rate' => 0,
                'started_at' => null,
                'completed_at' => null,
            ];
        }

        $successRate = $progress->total_emails > 0
            ? round(($progress->processed_emails / $progress->total_emails) * 100, 2)
            : 0;

        return [
            'status' => $progress->status,
            'total_emails' => $progress->total_emails,
            'processed' => $progress->processed_emails,
            'failed' => $progress->failed_emails,
            'success_rate' => $successRate,
            'started_at' => $progress->started_at,
            'completed_at' => $progress->completed_at,
            'last_error' => $progress->last_error,
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
        $progress = MigrationProgress::latest()->first();

        if (!$progress || !$progress->started_at) {
            return [
                'emails_per_minute' => 0,
                'estimated_completion' => null,
                'elapsed_time' => 0,
            ];
        }

        // Calculate elapsed time as absolute difference to ensure positive value
        $elapsedMinutes = abs($progress->started_at->diffInMinutes(now(), false));
        $emailsPerMinute = $elapsedMinutes > 0
            ? round($progress->processed_emails / $elapsedMinutes, 2)
            : 0;

        $remaining = $progress->total_emails - $progress->processed_emails;
        $estimatedMinutes = $emailsPerMinute > 0
            ? round($remaining / $emailsPerMinute)
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

        // Count jobs currently being processed (reserved but not yet completed)
        // Jobs with reserved_at timestamp are currently being processed
        $activeJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->count();

        // Get migration progress to calculate per-worker throughput
        $progress = MigrationProgress::latest()->first();

        $throughputPerWorker = 0;
        $avgJobsPerWorker = 0;

        if ($progress && $progress->started_at) {
            $elapsedMinutes = abs($progress->started_at->diffInMinutes(now(), false));

            if ($elapsedMinutes > 0 && $configuredWorkers > 0) {
                // Calculate emails processed per worker per minute
                $totalEmailsPerMinute = $progress->processed_emails / $elapsedMinutes;
                $throughputPerWorker = round($totalEmailsPerMinute / $configuredWorkers, 2);

                // Calculate average jobs processed per worker
                $avgJobsPerWorker = round($progress->processed_emails / $configuredWorkers, 0);
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
