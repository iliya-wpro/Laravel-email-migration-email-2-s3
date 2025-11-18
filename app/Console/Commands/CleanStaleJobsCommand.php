<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanStaleJobsCommand extends Command
{
    protected $signature = 'queue:clean-stale
                          {--timeout=300 : Job timeout in seconds}
                          {--dry-run : Show what would be cleaned without actually cleaning}';

    protected $description = 'Clean up stale reserved jobs that have exceeded timeout';

    public function handle(): int
    {
        $timeout = (int) $this->option('timeout');
        $dryRun = $this->option('dry-run');

        // Calculate threshold: jobs reserved before (now - timeout - buffer)
        $buffer = 60; // 1 minute buffer
        $threshold = now()->subSeconds($timeout + $buffer)->timestamp;

        $this->info("Cleaning stale jobs reserved before: " . date('Y-m-d H:i:s', $threshold));

        // Find stale jobs
        $staleJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', $threshold)
            ->get(['id', 'queue', 'reserved_at']);

        if ($staleJobs->isEmpty()) {
            $this->info('No stale jobs found.');
            return self::SUCCESS;
        }

        $this->warn("Found {$staleJobs->count()} stale reserved jobs:");

        foreach ($staleJobs as $job) {
            $reservedAt = date('Y-m-d H:i:s', $job->reserved_at);
            $this->line("  - Job ID: {$job->id}, Queue: {$job->queue}, Reserved: {$reservedAt}");
        }

        if ($dryRun) {
            $this->info("\nDry run mode - no changes made.");
            return self::SUCCESS;
        }

        // Clear reserved_at to make jobs available again
        $updated = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', $threshold)
            ->update([
                'reserved_at' => null,
                'attempts' => DB::raw('attempts + 1')
            ]);

        $this->info("\n✓ Cleared {$updated} stale job reservations.");

        return self::SUCCESS;
    }
}
