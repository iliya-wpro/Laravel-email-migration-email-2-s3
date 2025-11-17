<?php

namespace App\Console\Commands;

use App\Models\MigrationProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrationQueueStatusCommand extends Command
{
    protected $signature = 'emails:queue-status
                            {--batch= : Specific batch ID to check}
                            {--watch : Continuously monitor status}';

    protected $description = 'Display real-time queue migration status';

    public function handle(): int
    {
        do {
            if ($this->option('watch')) {
                $this->line("\033[2J\033[H"); // Clear screen
            }

            $this->displayStatus();

            if ($this->option('watch')) {
                sleep(2);
            }
        } while ($this->option('watch'));

        return 0;
    }

    private function displayStatus(): void
    {
        // Get batch progress
        if ($batchId = $this->option('batch')) {
            $progress = MigrationProgress::where('batch_id', $batchId)->first();
        } else {
            $progress = MigrationProgress::latest()->first();
        }

        if (!$progress) {
            $this->error('No migration found.');
            return;
        }

        // Get queue statistics
        $queueStats = DB::table('jobs')
            ->where('queue', 'email-migration')
            ->selectRaw('
                COUNT(*) as total_jobs,
                SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) as pending_jobs,
                SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as processing_jobs
            ')
            ->first();

        $failedCount = DB::table('failed_jobs')
            ->where('queue', 'email-migration')
            ->count();

        // Calculate metrics
        $completionPercentage = $progress->total_emails > 0
            ? round(($progress->processed_emails / $progress->total_emails) * 100, 2)
            : 0;

        $successRate = ($progress->processed_emails + $progress->failed_emails) > 0
            ? round(($progress->processed_emails / ($progress->processed_emails + $progress->failed_emails)) * 100, 2)
            : 0;

        $processingRate = $this->calculateProcessingRate($progress);

        // Display header
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('                EMAIL MIGRATION QUEUE STATUS                ');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        // Display batch info
        $this->line("Batch ID: <fg=yellow>{$progress->batch_id}</>");
        $this->line("Status: " . $this->formatStatus($progress->status));
        $this->newLine();

        // Display numbers in table format
        $this->table(
            ['Category', 'Count', 'Percentage'],
            [
                ['Total Emails', number_format($progress->total_emails), '100%'],
                ['Jobs Dispatched', number_format($progress->jobs_dispatched), $this->percentage($progress->jobs_dispatched, $progress->total_emails)],
                ['Successfully Processed', '<fg=green>' . number_format($progress->processed_emails) . '</>', $this->percentage($progress->processed_emails, $progress->total_emails)],
                ['Failed', '<fg=red>' . number_format($progress->failed_emails) . '</>', $this->percentage($progress->failed_emails, $progress->total_emails)],
                ['Remaining', number_format($progress->total_emails - $progress->processed_emails - $progress->failed_emails), $this->percentage($progress->total_emails - $progress->processed_emails - $progress->failed_emails, $progress->total_emails)],
            ]
        );

        // Display queue status
        $this->newLine();
        $this->info('Queue Status:');
        $this->table(
            ['Queue Metric', 'Value'],
            [
                ['Jobs in Queue (Pending)', number_format($queueStats->pending_jobs ?? 0)],
                ['Jobs Being Processed', number_format($queueStats->processing_jobs ?? 0)],
                ['Failed Jobs (Need Retry)', number_format($failedCount)],
            ]
        );

        // Display performance metrics
        $this->newLine();
        $this->info('Performance Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Completion', "{$completionPercentage}%"],
                ['Success Rate', "{$successRate}%"],
                ['Processing Rate', "{$processingRate} emails/min"],
                ['Time Elapsed', $this->getElapsedTime($progress)],
                ['Estimated Time Remaining', $this->getEstimatedTimeRemaining($progress, $processingRate)],
            ]
        );

        // Display progress bar
        $this->newLine();
        $bar = $this->output->createProgressBar($progress->total_emails);
        $bar->setProgress($progress->processed_emails + $progress->failed_emails);
        $bar->display();
        $this->newLine(2);

        if ($progress->status === 'completed') {
            $this->info('✓ Migration completed at ' . $progress->completed_at);
        }
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'pending' => '<fg=yellow>⏳ Pending</>',
            'processing' => '<fg=blue>⚙️  Processing</>',
            'completed' => '<fg=green>✓ Completed</>',
            'failed' => '<fg=red>✗ Failed</>',
            default => $status,
        };
    }

    private function percentage($value, $total): string
    {
        if ($total == 0) return '0%';
        return round(($value / $total) * 100, 2) . '%';
    }

    private function calculateProcessingRate(MigrationProgress $progress): float
    {
        if (!$progress->started_at) return 0;

        $minutes = $progress->started_at->diffInMinutes(now());
        if ($minutes == 0) return 0;

        return round(($progress->processed_emails + $progress->failed_emails) / $minutes, 2);
    }

    private function getElapsedTime(MigrationProgress $progress): string
    {
        if (!$progress->started_at) return 'Not started';

        $diff = $progress->started_at->diff(now());
        return sprintf('%02d:%02d:%02d', $diff->h + ($diff->days * 24), $diff->i, $diff->s);
    }

    private function getEstimatedTimeRemaining(MigrationProgress $progress, float $rate): string
    {
        if ($rate == 0) return 'Unknown';

        $remaining = $progress->total_emails - $progress->processed_emails - $progress->failed_emails;
        $minutes = round($remaining / $rate);

        if ($minutes < 60) {
            return "{$minutes} minutes";
        } elseif ($minutes < 1440) {
            $hours = round($minutes / 60, 1);
            return "{$hours} hours";
        } else {
            $days = round($minutes / 1440, 1);
            return "{$days} days";
        }
    }
}
