<?php

namespace App\Console\Commands;

use App\Jobs\MigrateEmailToS3Job;
use App\Models\Email;
use App\Models\MigrationProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateEmailsToS3Command extends Command
{
    protected $signature = 'emails:migrate-to-s3
                            {--batch-size=1000 : Number of emails to load per database query}
                            {--resume= : Resume from batch ID}
                            {--dry-run : Run without dispatching jobs}';

    protected $description = 'Dispatch email migration jobs to queue';

    public function handle(): int
    {
        $this->info('Starting email migration job dispatch...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No jobs will be dispatched.');
        }

        // Initialize or resume progress
        $progress = $this->initializeOrResume();

        // Display initial status
        $this->displayProgress($progress);

        // Get batch size
        $batchSize = (int) $this->option('batch-size');

        // Create progress bar
        $progressBar = $this->output->createProgressBar($progress->total_emails);
        $progressBar->setProgress($progress->jobs_dispatched);

        // Query emails in chunks
        $lastId = $progress->last_dispatched_id ?? 0;
        $totalDispatched = 0;

        do {
            $emails = Email::where('id', '>', $lastId)
                ->where('is_migrated', false)
                ->where('migration_attempts', '<', config('migration.max_attempts'))
                ->orderBy('id')
                ->limit($batchSize)
                ->get(['id']);

            if ($emails->isEmpty()) {
                break;
            }

            foreach ($emails as $email) {
                if (!$this->option('dry-run')) {
                    // Dispatch job to queue
                    MigrateEmailToS3Job::dispatch($email->id, $progress->batch_id)
                        ->onQueue('email-migration')
                        ->delay(now()->addSeconds($totalDispatched * 0.1)); // Stagger jobs slightly
                }

                $totalDispatched++;
                $progressBar->advance();
                $lastId = $email->id;
            }

            // Update progress after each batch
            if (!$this->option('dry-run')) {
                $progress->update([
                    'last_dispatched_id' => $lastId,
                    'jobs_dispatched' => $progress->jobs_dispatched + $emails->count(),
                    'status' => 'processing'
                ]);
            }

            // Free memory
            unset($emails);

        } while (true);

        $progressBar->finish();
        $this->newLine(2);

        if ($totalDispatched === 0) {
            $this->info('No emails found to migrate.');
            $progress->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);
        } else {
            $this->info("Successfully dispatched {$totalDispatched} migration jobs to queue.");
            $this->newLine();
            $this->warn('IMPORTANT: Jobs are now in the queue. Start queue workers to process them:');
            $this->line('docker-compose exec app php artisan queue:work --queue=email-migration --tries=3 --timeout=300');
            $this->newLine();
            $this->info('To run multiple concurrent workers (e.g., 10 workers):');
            $this->line('docker-compose exec app supervisorctl start email-migration:*');
        }

        return 0;
    }

    private function initializeOrResume(): MigrationProgress
    {
        if ($batchId = $this->option('resume')) {
            $progress = MigrationProgress::where('batch_id', $batchId)->first();

            if (!$progress) {
                throw new \Exception("Batch ID not found: {$batchId}");
            }

            $this->info("Resuming migration from batch: {$batchId}");
            return $progress;
        }

        // Create new batch
        $batchId = Str::uuid()->toString();

        $totalEmails = Email::where('is_migrated', false)
            ->where('migration_attempts', '<', config('migration.max_attempts'))
            ->count();

        return MigrationProgress::create([
            'batch_id' => $batchId,
            'last_processed_email_id' => 0,
            'last_dispatched_id' => 0,
            'total_emails' => $totalEmails,
            'jobs_dispatched' => 0,
            'processed_emails' => 0,
            'failed_emails' => 0,
            'status' => 'pending',
            'started_at' => now(),
        ]);
    }

    private function displayProgress(MigrationProgress $progress): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Batch ID', $progress->batch_id],
                ['Total Emails', number_format($progress->total_emails)],
                ['Jobs Dispatched', number_format($progress->jobs_dispatched)],
                ['Processed', number_format($progress->processed_emails)],
                ['Failed', number_format($progress->failed_emails)],
                ['Status', $progress->status],
            ]
        );
    }
}
