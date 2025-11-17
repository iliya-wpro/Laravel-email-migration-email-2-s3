<?php

namespace App\Console\Commands;

use App\Contracts\Services\EmailMigrationServiceInterface;
use App\Models\MigrationProgress;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateEmailsToS3Command extends Command
{
    protected $signature = 'emails:migrate-to-s3
                            {--workers=1 : Number of concurrent workers}
                            {--resume= : Resume from batch ID}
                            {--dry-run : Run without making changes}';

    protected $description = 'Migrate email bodies and attachments to S3';

    private EmailMigrationServiceInterface $migrationService;
    private ?string $lockFile = null;
    private $progressBar = null;

    public function __construct(EmailMigrationServiceInterface $migrationService)
    {
        parent::__construct();
        $this->migrationService = $migrationService;
    }

    public function handle(): int
    {
        if (!$this->acquireLock()) {
            $this->error('Migration is already running.');
            return 1;
        }

        try {
            $this->info('Starting email migration to S3...');

            if ($this->option('dry-run')) {
                $this->warn('DRY RUN MODE - No changes will be made.');
            }

            $progress = $this->initializeOrResume();

            $this->displayProgress($progress);

            $this->progressBar = $this->output->createProgressBar($progress->total_emails);
            $this->progressBar->setProgress($progress->processed_emails);

            while (true) {
                $result = $this->migrationService->processBatch();

                $this->updateProgressBar($result->processedCount, $result->failedCount);

                if ($result->isComplete) {
                    break;
                }

                // Prevent memory leaks
                $this->clearMemory();
            }

            $this->progressBar->finish();
            $this->newLine(2);
            $this->info('Migration completed successfully!');

            $this->displayFinalStats($progress->fresh());

            return 0;
        } catch (Exception $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            Log::error('Migration failed', ['error' => $e]);
            return 1;
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): bool
    {
        $this->lockFile = storage_path('app/migration.lock');

        if (file_exists($this->lockFile)) {
            $pid = (int) file_get_contents($this->lockFile);

            // Check if process is still running (POSIX systems)
            if ($pid > 0 && function_exists('posix_getsid') && posix_getsid($pid) !== false) {
                return false;
            }
        }

        file_put_contents($this->lockFile, getmypid());
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockFile && file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    private function initializeOrResume(): MigrationProgress
    {
        if ($batchId = $this->option('resume')) {
            $progress = MigrationProgress::where('batch_id', $batchId)->first();

            if (!$progress) {
                throw new Exception("Batch ID not found: {$batchId}");
            }

            $this->info("Resuming migration from batch: {$batchId}");
            $this->migrationService->setProgress($progress);
            return $progress;
        }

        return $this->migrationService->initializeMigration();
    }

    private function clearMemory(): void
    {
        if (memory_get_usage() > 100 * 1024 * 1024) { // 100MB
            DB::connection()->disconnect();
            DB::connection()->reconnect();
            gc_collect_cycles();
        }
    }

    private function displayProgress(MigrationProgress $progress): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Batch ID', $progress->batch_id],
                ['Total Emails', number_format($progress->total_emails)],
                ['Processed', number_format($progress->processed_emails)],
                ['Failed', number_format($progress->failed_emails)],
                ['Last ID', $progress->last_processed_email_id],
            ]
        );
    }

    private function updateProgressBar(int $processed, int $failed): void
    {
        if ($this->progressBar) {
            $this->progressBar->advance($processed + $failed);
        }

        if ($failed > 0) {
            $this->newLine();
            $this->warn("Batch completed: {$processed} processed, {$failed} failed");
        }
    }

    private function displayFinalStats(MigrationProgress $progress): void
    {
        $duration = $progress->started_at
            ? now()->diffInMinutes($progress->started_at)
            : 0;

        $rate = $duration > 0
            ? round($progress->processed_emails / $duration, 2)
            : 0;

        $successRate = $progress->processed_emails > 0
            ? round(($progress->processed_emails - $progress->failed_emails) / $progress->processed_emails * 100, 2)
            : 0;

        $this->table(
            ['Final Statistics', 'Value'],
            [
                ['Total Duration', "{$duration} minutes"],
                ['Emails Processed', number_format($progress->processed_emails)],
                ['Emails Failed', number_format($progress->failed_emails)],
                ['Success Rate', "{$successRate}%"],
                ['Processing Rate', "{$rate} emails/min"],
                ['Completed At', $progress->completed_at],
            ]
        );
    }
}
