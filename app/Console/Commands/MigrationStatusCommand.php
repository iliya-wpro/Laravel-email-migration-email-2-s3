<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\MigrationProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MigrationStatusCommand extends Command
{
    protected $signature = 'emails:migration-status {--batch= : Specific batch ID to check}';

    protected $description = 'Display the status of email migration';

    public function handle(): int
    {
        if ($batchId = $this->option('batch')) {
            $progress = MigrationProgress::where('batch_id', $batchId)->first();
        } else {
            $progress = MigrationProgress::latest()->first();
        }

        if (!$progress) {
            $this->error('No migration found.');
            return 1;
        }

        $this->displayDetailedStatus($progress);

        if ($progress->status === 'processing') {
            $this->displayCurrentRate($progress);
        }

        if ($progress->failed_emails > 0) {
            $this->displayFailedEmails();
        }

        return 0;
    }

    private function displayDetailedStatus(MigrationProgress $progress): void
    {
        $completionPercentage = $progress->total_emails > 0
            ? round(($progress->processed_emails / $progress->total_emails) * 100, 2)
            : 0;

        $duration = $progress->started_at
            ? now()->diffInMinutes($progress->started_at)
            : 0;

        $rate = $duration > 0
            ? round($progress->processed_emails / $duration, 2)
            : 0;

        $eta = $rate > 0
            ? round(($progress->total_emails - $progress->processed_emails) / $rate)
            : 'Unknown';

        $successRate = $progress->processed_emails > 0
            ? round(100 - ($progress->failed_emails / $progress->processed_emails * 100), 2)
            : 100;

        $this->info('Migration Status Report');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Batch ID', $progress->batch_id],
                ['Status', $this->formatStatus($progress->status)],
                ['Progress', "{$completionPercentage}%"],
                ['Total Emails', number_format($progress->total_emails)],
                ['Processed', number_format($progress->processed_emails)],
                ['Failed', number_format($progress->failed_emails)],
                ['Success Rate', "{$successRate}%"],
                ['Processing Rate', "{$rate} emails/min"],
                ['Duration', "{$duration} minutes"],
                ['ETA', is_numeric($eta) ? "{$eta} minutes" : $eta],
                ['Last Processed ID', $progress->last_processed_email_id],
                ['Started At', $progress->started_at?->format('Y-m-d H:i:s') ?? 'Not started'],
                ['Completed At', $progress->completed_at?->format('Y-m-d H:i:s') ?? 'In Progress'],
            ]
        );
    }

    private function displayCurrentRate(MigrationProgress $progress): void
    {
        $this->newLine();
        $this->info('Current Processing Status:');

        $bar = $this->output->createProgressBar($progress->total_emails);
        $bar->setProgress($progress->processed_emails);
        $bar->finish();
        $this->newLine(2);
    }

    private function displayFailedEmails(): void
    {
        $failed = Email::where('is_migrated', false)
            ->where('migration_attempts', '>=', config('migration.max_attempts'))
            ->limit(10)
            ->get(['id', 'migration_attempts', 'migration_error']);

        if ($failed->isEmpty()) {
            return;
        }

        $this->error("\nFailed Emails (showing first 10):");
        $this->table(
            ['Email ID', 'Attempts', 'Error'],
            $failed->map(fn ($e) => [
                $e->id,
                $e->migration_attempts,
                Str::limit($e->migration_error, 50),
            ])
        );
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'pending' => '<fg=yellow>Pending</>',
            'processing' => '<fg=blue>Processing</>',
            'completed' => '<fg=green>Completed</>',
            'failed' => '<fg=red>Failed</>',
            default => $status,
        };
    }
}
