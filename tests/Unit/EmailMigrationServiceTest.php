<?php

namespace Tests\Unit;

use App\Models\Email;
use App\Models\File;
use App\Models\MigrationProgress;
use App\Repositories\EmailRepository;
use App\Repositories\FileRepository;
use App\Services\EmailMigrationService;
use App\Services\S3Service;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailMigrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailMigrationService $service;
    private MockInterface $s3Mock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s3Mock = Mockery::mock(S3Service::class);
        $this->app->instance(S3Service::class, $this->s3Mock);

        $this->service = app(EmailMigrationService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_email_migration_uploads_body_and_attachments(): void
    {
        // Create test files first
        $file1 = File::factory()->create();
        $file2 = File::factory()->create();

        // Create placeholder files on disk
        $this->createPlaceholderFile($file1);
        $this->createPlaceholderFile($file2);

        // Create test email with attachments
        $email = Email::factory()->withFiles([$file1->id, $file2->id])->create([
            'body' => '<html>Test content</html>',
            'is_migrated' => false,
        ]);

        // Set expectations
        $this->s3Mock->shouldReceive('uploadContent')
            ->once()
            ->with(
                $email->body,
                Mockery::pattern('/^emails\/\d+\/\d+\.html$/'),
                'text/html'
            )
            ->andReturn('emails/0/1.html');

        $this->s3Mock->shouldReceive('uploadFile')
            ->twice()
            ->andReturn('attachments/test.pdf');

        // Run migration
        $progress = $this->service->initializeMigration();
        $result = $this->service->processBatch();

        // Assertions
        $this->assertEquals(1, $result->processedCount);
        $this->assertEquals(0, $result->failedCount);
        $this->assertFalse($result->isComplete);

        $email->refresh();
        $this->assertTrue($email->is_migrated);
        $this->assertNotNull($email->body_s3_path);
        $this->assertNotNull($email->file_s3_paths);
        $this->assertIsArray($email->file_s3_paths);
    }

    public function test_migration_handles_failures_gracefully(): void
    {
        $email = Email::factory()->create(['is_migrated' => false]);

        $this->s3Mock->shouldReceive('uploadContent')
            ->once()
            ->andThrow(new Exception('S3 upload failed'));

        $progress = $this->service->initializeMigration();
        $result = $this->service->processBatch();

        $this->assertEquals(0, $result->processedCount);
        $this->assertEquals(1, $result->failedCount);
        $this->assertFalse($result->isComplete);

        $email->refresh();
        $this->assertFalse($email->is_migrated);
        $this->assertEquals(1, $email->migration_attempts);
        $this->assertNotNull($email->migration_error);
        $this->assertEquals('S3 upload failed', $email->migration_error);
    }

    public function test_migration_is_idempotent(): void
    {
        Email::factory()->migrated()->create();

        $this->s3Mock->shouldNotReceive('uploadContent');

        $progress = $this->service->initializeMigration();
        $result = $this->service->processBatch();

        // No unmigrated emails, so should complete immediately
        $this->assertEquals(0, $result->processedCount);
        $this->assertTrue($result->isComplete);
    }

    public function test_migration_skips_emails_exceeding_max_attempts(): void
    {
        Email::factory()->create([
            'is_migrated' => false,
            'migration_attempts' => config('migration.max_attempts'),
        ]);

        $this->s3Mock->shouldNotReceive('uploadContent');

        $progress = $this->service->initializeMigration();
        $result = $this->service->processBatch();

        $this->assertEquals(0, $result->processedCount);
        $this->assertTrue($result->isComplete);
    }

    public function test_migration_processes_emails_in_id_order(): void
    {
        $email1 = Email::factory()->create(['is_migrated' => false]);
        $email3 = Email::factory()->create(['is_migrated' => false]);
        $email2 = Email::factory()->create(['is_migrated' => false]);

        $processedIds = [];

        $this->s3Mock->shouldReceive('uploadContent')
            ->times(3)
            ->andReturnUsing(function ($content, $key) use (&$processedIds) {
                preg_match('/emails\/\d+\/(\d+)\.html/', $key, $matches);
                $processedIds[] = (int) $matches[1];
                return $key;
            });

        $this->service->initializeMigration();
        $this->service->processBatch();

        // Should process in ID order
        $this->assertEquals([$email1->id, $email3->id, $email2->id], $processedIds);
    }

    public function test_progress_is_updated_correctly(): void
    {
        Email::factory()->count(3)->create(['is_migrated' => false]);

        $this->s3Mock->shouldReceive('uploadContent')
            ->times(3)
            ->andReturn('emails/test.html');

        $progress = $this->service->initializeMigration();
        $this->assertEquals(3, $progress->total_emails);
        $this->assertEquals(0, $progress->processed_emails);
        $this->assertEquals('pending', $progress->status);

        $result = $this->service->processBatch();

        $progress->refresh();
        $this->assertEquals(3, $progress->processed_emails);
        $this->assertEquals(0, $progress->failed_emails);
        $this->assertEquals('processing', $progress->status);
    }

    public function test_migration_completes_when_no_more_emails(): void
    {
        // No unmigrated emails
        $progress = $this->service->initializeMigration();
        $result = $this->service->processBatch();

        $this->assertTrue($result->isComplete);

        $progress->refresh();
        $this->assertEquals('completed', $progress->status);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_migration_handles_missing_files_gracefully(): void
    {
        // Create file record but don't create the actual file
        $file = File::factory()->create();

        $email = Email::factory()->withFiles([$file->id])->create();

        $this->s3Mock->shouldReceive('uploadContent')
            ->once()
            ->andReturn('emails/test.html');

        // Should fail because file doesn't exist on disk
        $this->service->initializeMigration();
        $result = $this->service->processBatch();

        $this->assertEquals(0, $result->processedCount);
        $this->assertEquals(1, $result->failedCount);

        $email->refresh();
        $this->assertFalse($email->is_migrated);
        $this->assertStringContainsString('File not found', $email->migration_error);
    }

    private function createPlaceholderFile(File $file): void
    {
        $fullPath = storage_path('app/' . $file->path);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($fullPath, 'Test content');
    }
}
