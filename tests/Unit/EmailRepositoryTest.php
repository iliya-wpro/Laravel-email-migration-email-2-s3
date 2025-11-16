<?php

namespace Tests\Unit;

use App\Models\Email;
use App\Repositories\EmailRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EmailRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EmailRepository();
    }

    public function test_get_next_batch_returns_unmigrated_emails(): void
    {
        Email::factory()->count(5)->create(['is_migrated' => false]);
        Email::factory()->count(3)->migrated()->create();

        $batch = $this->repository->getNextBatch(0, 10);

        $this->assertCount(5, $batch);
        $batch->each(fn ($email) => $this->assertFalse($email->is_migrated));
    }

    public function test_get_next_batch_respects_last_id(): void
    {
        $email1 = Email::factory()->create(['is_migrated' => false]);
        $email2 = Email::factory()->create(['is_migrated' => false]);
        $email3 = Email::factory()->create(['is_migrated' => false]);

        $batch = $this->repository->getNextBatch($email1->id, 10);

        $this->assertCount(2, $batch);
        $this->assertEquals($email2->id, $batch->first()->id);
    }

    public function test_get_next_batch_respects_limit(): void
    {
        Email::factory()->count(10)->create(['is_migrated' => false]);

        $batch = $this->repository->getNextBatch(0, 3);

        $this->assertCount(3, $batch);
    }

    public function test_get_next_batch_skips_exceeded_attempts(): void
    {
        Email::factory()->create([
            'is_migrated' => false,
            'migration_attempts' => config('migration.max_attempts'),
        ]);
        $validEmail = Email::factory()->create([
            'is_migrated' => false,
            'migration_attempts' => 0,
        ]);

        $batch = $this->repository->getNextBatch(0, 10);

        $this->assertCount(1, $batch);
        $this->assertEquals($validEmail->id, $batch->first()->id);
    }

    public function test_count_unmigrated_emails(): void
    {
        Email::factory()->count(5)->create(['is_migrated' => false]);
        Email::factory()->count(3)->migrated()->create();
        Email::factory()->create([
            'is_migrated' => false,
            'migration_attempts' => config('migration.max_attempts'),
        ]);

        $count = $this->repository->countUnmigratedEmails();

        $this->assertEquals(5, $count);
    }

    public function test_get_failed_emails(): void
    {
        Email::factory()->count(3)->create([
            'is_migrated' => false,
            'migration_attempts' => config('migration.max_attempts'),
        ]);
        Email::factory()->count(2)->create([
            'is_migrated' => false,
            'migration_attempts' => 1,
        ]);

        $failed = $this->repository->getFailedEmails();

        $this->assertCount(3, $failed);
    }
}
