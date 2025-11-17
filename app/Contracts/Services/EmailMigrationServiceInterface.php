<?php

namespace App\Contracts\Services;

use App\Models\MigrationProgress;
use App\ValueObjects\ProcessingResult;

interface EmailMigrationServiceInterface
{
    /**
     * Initialize a new migration batch.
     *
     * @return MigrationProgress
     */
    public function initializeMigration(): MigrationProgress;

    /**
     * Set the progress tracker (for resuming).
     *
     * @param MigrationProgress $progress
     * @return void
     */
    public function setProgress(MigrationProgress $progress): void;

    /**
     * Process a batch of emails.
     *
     * @return ProcessingResult
     */
    public function processBatch(): ProcessingResult;

    /**
     * Get current progress.
     *
     * @return MigrationProgress|null
     */
    public function getProgress(): ?MigrationProgress;
}
