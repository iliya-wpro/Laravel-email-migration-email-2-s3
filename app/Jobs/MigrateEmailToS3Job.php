<?php

namespace App\Jobs;

use App\Contracts\Repositories\EmailRepositoryInterface;
use App\Contracts\Repositories\FileRepositoryInterface;
use App\Contracts\Services\S3ServiceInterface;
use App\Exceptions\FileNotFoundException;
use App\Models\Email;
use App\Models\File;
use App\Models\MigrationProgress;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateEmailToS3Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $retryAfter = 300; // 5 minutes

    /**
     * The email ID to migrate.
     *
     * @var int
     */
    private int $emailId;

    /**
     * The batch ID for tracking.
     *
     * @var string
     */
    private string $batchId;

    /**
     * Create a new job instance.
     *
     * @param int $emailId
     * @param string $batchId
     */
    public function __construct(int $emailId, string $batchId)
    {
        $this->emailId = $emailId;
        $this->batchId = $batchId;
        $this->queue = 'email-migration';
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Exception
     */
    public function handle(): void
    {
        $s3Service = app(S3ServiceInterface::class);
        $emailRepo = app(EmailRepositoryInterface::class);
        $fileRepo = app(FileRepositoryInterface::class);

        DB::beginTransaction();

        try {
            // Find the email
            $email = $emailRepo->find($this->emailId);

            if (!$email) {
                Log::error("Email not found", ['email_id' => $this->emailId]);
                DB::rollBack();
                return;
            }

            // Skip if already migrated
            if ($email->is_migrated) {
                Log::info("Email already migrated", ['email_id' => $this->emailId]);
                DB::rollBack();
                $this->updateProgress(false, false);
                return;
            }

            // Skip if exceeded max attempts
            if ($email->migration_attempts >= config('migration.max_attempts')) {
                Log::warning("Email exceeded max attempts", [
                    'email_id' => $this->emailId,
                    'attempts' => $email->migration_attempts
                ]);
                DB::rollBack();
                $this->updateProgress(false, true);
                return;
            }

            // Upload HTML body
            $bodyPath = $this->uploadEmailBody($email, $s3Service);

            // Upload attachments
            $attachmentPaths = $this->uploadAttachments($email, $s3Service, $fileRepo);

            // Update email record
            $email->update([
                'body_s3_path' => $bodyPath,
                'file_s3_paths' => $attachmentPaths,
                'is_migrated' => true,
                'migration_attempted_at' => now(),
            ]);

            DB::commit();

            // Update progress tracker
            $this->updateProgress(true, false);

            Log::info("Email migrated successfully", [
                'email_id' => $this->emailId,
                'body_path' => $bodyPath,
                'attachments' => count($attachmentPaths)
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            // Update email with failure info
            Email::where('id', $this->emailId)
                ->increment('migration_attempts', 1, [
                    'migration_attempted_at' => now(),
                    'migration_error' => $e->getMessage()
                ]);

            // Update progress tracker
            $this->updateProgress(false, true);

            Log::error("Email migration failed", [
                'email_id' => $this->emailId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Upload email body HTML to S3.
     *
     * @param Email $email
     * @param S3ServiceInterface $s3Service
     * @return string The S3 path
     */
    private function uploadEmailBody(Email $email, S3ServiceInterface $s3Service): string
    {
        $fileName = sprintf(
            'emails/%d/%d.html',
            floor($email->id / 1000),
            $email->id
        );

        return $s3Service->uploadContent(
            $email->body,
            $fileName,
            'text/html'
        );
    }

    /**
     * Upload email attachments to S3.
     *
     * @param Email $email
     * @param S3ServiceInterface $s3Service
     * @param FileRepositoryInterface $fileRepo
     * @return array Map of file IDs to S3 paths
     * @throws FileNotFoundException
     */
    private function uploadAttachments(
        Email $email,
        S3ServiceInterface $s3Service,
        FileRepositoryInterface $fileRepo
    ): array {
        $paths = [];
        $fileIds = $email->file_ids ?? [];

        if (is_string($fileIds)) {
            $fileIds = json_decode($fileIds, true) ?? [];
        }

        foreach ($fileIds as $fileId) {
            $file = $fileRepo->find($fileId);
            if (!$file) {
                Log::warning("File not found for email attachment", [
                    'email_id' => $email->id,
                    'file_id' => $fileId
                ]);
                continue;
            }

            $s3Path = $this->uploadFile($file, $s3Service);
            $paths[$fileId] = $s3Path;

            $file->update([
                's3_path' => $s3Path,
                'is_migrated' => true,
            ]);
        }

        return $paths;
    }

    /**
     * Upload a single file to S3.
     *
     * @param File $file
     * @param S3ServiceInterface $s3Service
     * @return string The S3 path
     * @throws FileNotFoundException
     */
    private function uploadFile(File $file, S3ServiceInterface $s3Service): string
    {
        $localPath = storage_path('app/' . $file->path);

        if (!file_exists($localPath)) {
            throw new FileNotFoundException("File not found: {$localPath}");
        }

        $s3Path = sprintf(
            'attachments/%d/%s',
            floor($file->id / 1000),
            $file->name
        );

        return $s3Service->uploadFile($localPath, $s3Path);
    }

    /**
     * Update migration progress.
     *
     * @param bool $success
     * @param bool $failed
     * @return void
     */
    private function updateProgress(bool $success, bool $failed): void
    {
        // Use atomic increments without row locks to allow parallel updates
        $updates = [];
        if ($success) {
            $updates['processed_emails'] = DB::raw('processed_emails + 1');
        }
        if ($failed) {
            $updates['failed_emails'] = DB::raw('failed_emails + 1');
        }

        if (empty($updates)) {
            return;
        }

        // Perform atomic update without locking
        MigrationProgress::where('batch_id', $this->batchId)->update($updates);

        // Check if migration is complete (separate query to avoid lock contention)
        $progress = MigrationProgress::where('batch_id', $this->batchId)->first();
        if ($progress) {
            $totalProcessed = $progress->processed_emails + $progress->failed_emails;
            if ($totalProcessed >= $progress->total_emails && $progress->status !== 'completed') {
                $progress->update([
                    'status' => 'completed',
                    'completed_at' => now()
                ]);
            }
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error("Job permanently failed for email", [
            'email_id' => $this->emailId,
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage()
        ]);

        // Mark as permanently failed after all retries exhausted
        Email::where('id', $this->emailId)->update([
            'migration_attempts' => config('migration.max_attempts'),
            'migration_error' => 'Job permanently failed: ' . $exception->getMessage(),
            'migration_attempted_at' => now()
        ]);

        // Update progress
        $this->updateProgress(false, true);
    }
}
