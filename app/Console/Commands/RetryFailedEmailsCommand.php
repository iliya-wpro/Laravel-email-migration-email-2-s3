<?php

namespace App\Console\Commands;

use App\Models\Email;
use Illuminate\Console\Command;

class RetryFailedEmailsCommand extends Command
{
    protected $signature = 'emails:retry-failed
                            {--limit=100 : Number of emails to retry}
                            {--reset : Reset attempt counter}';

    protected $description = 'Retry failed email migrations';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $reset = $this->option('reset');

        $failed = Email::where('is_migrated', false)
            ->where('migration_attempts', '>=', config('migration.max_attempts'))
            ->limit($limit)
            ->get();

        if ($failed->isEmpty()) {
            $this->info('No failed emails found.');
            return 0;
        }

        $this->info("Found {$failed->count()} failed emails to retry.");

        if ($reset) {
            if (!$this->confirm('This will reset the attempt counter for these emails. Continue?')) {
                $this->info('Operation cancelled.');
                return 0;
            }

            $failed->each(function ($email) {
                $email->update([
                    'migration_attempts' => 0,
                    'migration_error' => null,
                ]);
            });

            $this->info('Reset attempt counters for ' . $failed->count() . ' emails.');
            $this->info('Run "php artisan emails:migrate-to-s3" to retry the migration.');
        } else {
            $this->warn('Run with --reset flag to reset attempt counters.');

            $this->table(
                ['Email ID', 'Attempts', 'Last Error'],
                $failed->map(fn ($e) => [
                    $e->id,
                    $e->migration_attempts,
                    \Illuminate\Support\Str::limit($e->migration_error, 60),
                ])->take(20)
            );

            if ($failed->count() > 20) {
                $this->info('... and ' . ($failed->count() - 20) . ' more');
            }
        }

        return 0;
    }
}
