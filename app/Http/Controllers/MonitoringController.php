<?php

namespace App\Http\Controllers;

use App\Models\MigrationProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitoringController
{
    public function dashboard(): View
    {
        $stats = $this->gatherStats();

        return view('monitoring.dashboard', compact('stats'));
    }

    private function gatherStats(): array
    {
        return [
            'queue' => $this->getQueueStats(),
            'migration' => $this->getMigrationStats(),
            'system' => $this->getSystemStats(),
            'recent_errors' => $this->getRecentErrors(),
            'performance' => $this->getPerformanceStats(),
        ];
    }

    private function getQueueStats(): array
    {
        $jobs = DB::table('jobs')->get();
        $failedJobs = DB::table('failed_jobs')->get();

        $pending = $jobs->count();
        $failed = $failedJobs->count();

        $oldestJob = $jobs->sortBy('created_at')->first();
        $oldestTimestamp = $oldestJob ? $oldestJob->created_at : null;

        return [
            'pending' => $pending,
            'failed' => $failed,
            'oldest_job_age' => $oldestTimestamp
                ? now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp($oldestTimestamp))
                : 0,
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

        $elapsedMinutes = now()->diffInMinutes($progress->started_at);
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
            'elapsed_time' => $elapsedMinutes,
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
