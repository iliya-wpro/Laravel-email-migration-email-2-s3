<?php

namespace App\Services;

use App\Contracts\Repositories\EmailRepositoryInterface;
use App\Contracts\Repositories\FileRepositoryInterface;
use App\Contracts\Services\EmailMigrationServiceInterface;
use App\Contracts\Services\S3ServiceInterface;
use App\Exceptions\FileNotFoundException;
use App\Models\Email;
use App\Models\File;
use App\Models\MigrationProgress;
use App\ValueObjects\ProcessingResult;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailMigrationService implements EmailMigrationServiceInterface
{
    private S3ServiceInterface $s3Service;
    private EmailRepositoryInterface $emailRepo;
    private FileRepositoryInterface $fileRepo;
    private int $batchSize;
    private ?MigrationProgress $progress = null;

    public function __construct(
        S3ServiceInterface $s3Service,
        EmailRepositoryInterface $emailRepo,
        FileRepositoryInterface $fileRepo
    ) {
        $this->s3Service = $s3Service;
        $this->emailRepo = $emailRepo;
        $this->fileRepo = $fileRepo;
        $this->batchSize = config('migration.batch_size');
    }

    /**
     * Initialize a new migration batch.
     *
     * @return MigrationProgress
     */
    public function initializeMigration(): MigrationProgress
    {
        $batchId = Str::uuid()->toString();

        $this->progress = MigrationProgress::create([
            'batch_id' => $batchId,
            'last_processed_email_id' => 0,
            'total_emails' => $this->emailRepo->countUnmigratedEmails(),
            'processed_emails' => 0,
            'failed_emails' => 0,
            'status' => 'pending',
            'started_at' => now(),
        ]);

        return $this->progress;
    }

    /**
     * Set the progress tracker (for resuming).
     *
     * @param MigrationProgress $progress
     * @return void
     */
    public function setProgress(MigrationProgress $progress): void
    {
        $this->progress = $progress;
    }

    /**
     * Process a batch of emails.
     *
     * @return ProcessingResult
     */
    public function processBatch(): ProcessingResult
    {
        if (!$this->progress) {
            throw new Exception('Migration not initialized. Call initializeMigration() first.');
        }

        $this->progress->update(['status' => 'processing']);

        $emails = $this->emailRepo->getNextBatch(
            $this->progress->last_processed_email_id,
            $this->batchSize
        );

        if ($emails->isEmpty()) {
            $this->completeMigration();
            return new ProcessingResult(true, 0, 0);
        }

        $processed = 0;
        $failed = 0;

        foreach ($emails as $email) {
            DB::beginTransaction();
            try {
                $this->migrateEmail($email);
                $processed++;
                DB::commit();
            } catch (Exception $e) {
                DB::rollBack();
                $this->handleFailure($email, $e);
                $failed++;
            }
        }

        $this->updateProgress($emails->last()->id, $processed, $failed);

        return new ProcessingResult(false, $processed, $failed);
    }

    /**
     * Migrate a single email to S3.
     *
     * @param Email $email
     * @return void
     */
    private function migrateEmail(Email $email): void
    {
        // Upload HTML body
        $bodyPath = $this->uploadEmailBody($email);

        // Upload attachments
        $attachmentPaths = $this->uploadAttachments($email);

        // Update email record
        $email->update([
            'body_s3_path' => $bodyPath,
            'file_s3_paths' => $attachmentPaths,
            'is_migrated' => true,
            'migration_attempted_at' => now(),
        ]);
    }

    /**
     * Upload email body HTML to S3.
     *
     * @param Email $email
     * @return string The S3 path
     */
    private function uploadEmailBody(Email $email): string
    {
        $fileName = sprintf(
            'emails/%d/%d.html',
            floor($email->id / 1000),
            $email->id
        );

        return $this->s3Service->uploadContent(
            $email->body,
            $fileName,
            'text/html'
        );
    }

    /**
     * Upload email attachments to S3.
     *
     * @param Email $email
     * @return array Map of file IDs to S3 paths
     */
    private function uploadAttachments(Email $email): array
    {
        $paths = [];
        $fileIds = $email->file_ids ?? [];

        if (is_string($fileIds)) {
            $fileIds = json_decode($fileIds, true) ?? [];
        }

        foreach ($fileIds as $fileId) {
            $file = $this->fileRepo->find($fileId);
            if (!$file) {
                continue;
            }

            $s3Path = $this->uploadFile($file);
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
     * @return string The S3 path
     * @throws FileNotFoundException
     */
    private function uploadFile(File $file): string
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

        return $this->s3Service->uploadFile($localPath, $s3Path);
    }

    /**
     * Update migration progress.
     *
     * @param int $lastId
     * @param int $processed
     * @param int $failed
     * @return void
     */
    private function updateProgress(int $lastId, int $processed, int $failed): void
    {
        $this->progress->increment('processed_emails', $processed);
        $this->progress->increment('failed_emails', $failed);
        $this->progress->update(['last_processed_email_id' => $lastId]);
    }

    /**
     * Mark migration as complete.
     *
     * @return void
     */
    private function completeMigration(): void
    {
        $this->progress->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle migration failure for an email.
     *
     * @param Email $email
     * @param Exception $e
     * @return void
     */
    private function handleFailure(Email $email, Exception $e): void
    {
        $email->increment('migration_attempts');
        $email->update([
            'migration_attempted_at' => now(),
            'migration_error' => $e->getMessage(),
        ]);

        Log::error('Email migration failed', [
            'email_id' => $email->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Get current progress.
     *
     * @return MigrationProgress|null
     */
    public function getProgress(): ?MigrationProgress
    {
        return $this->progress;
    }
}
