<?php

namespace Database\Seeders;

use App\Models\Email;
use App\Models\File;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating 100,000 email records...');

        // Create files in batches
        $this->command->info('Creating 1,000 file records in batches...');
        $files = [];
        $fileBatchSize = 10;

        for ($batch = 0; $batch < 100; $batch++) {
            $batchFiles = File::factory()->count($fileBatchSize)->create()->pluck('id')->toArray();
            $files = array_merge($files, $batchFiles);

            if (($batch + 1) % 10 === 0) {
                $this->command->info("Created " . count($files) . " files...");
            }
        }
        $this->command->info('Files created successfully.');

        // Create placeholder files in batches
        $this->createPlaceholderFiles($files);

        $bar = $this->command->getOutput()->createProgressBar(100000);
        $bar->start();

        // Generate emails in smaller chunks to save memory
        $emailBatchSize = 10;
        $totalBatches = 10000;

        for ($i = 0; $i < $totalBatches; $i++) {
            $emails = [];

            for ($j = 0; $j < $emailBatchSize; $j++) {
                $fileCount = rand(0, 3);
                $selectedFiles = [];

                if ($fileCount > 0 && count($files) > 0) {
                    $keys = array_rand($files, min($fileCount, count($files)));
                    if (!is_array($keys)) {
                        $keys = [$keys];
                    }
                    $selectedFiles = array_map(fn ($key) => $files[$key], $keys);
                }

                $emails[] = [
                    'client_id' => rand(1, 100),
                    'loan_id' => rand(1, 1000),
                    'email_template_id' => rand(1, 20),
                    'receiver_email' => fake()->email(),
                    'sender_email' => 'system@company.com',
                    'subject' => fake()->sentence(),
                    'body' => $this->generateHtmlBody(),
                    'file_ids' => json_encode($selectedFiles),
                    'is_migrated' => false,
                    'migration_attempts' => 0,
                    'sent_at' => fake()->dateTimeBetween('-1 year'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $bar->advance();
            }

            Email::insert($emails);

            // Clear memory periodically
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('Seeding completed!');
        $this->command->info('Total emails: ' . number_format(Email::count()));
        $this->command->info('Total files: ' . number_format(File::count()));
    }

    private function generateHtmlBody(): string
    {
        $paragraphCount = rand(3, 10);
        $paragraphs = [];

        for ($i = 0; $i < $paragraphCount; $i++) {
            $paragraphs[] = '<p>' . fake()->paragraph(rand(3, 8)) . '</p>';
        }

        return sprintf(
            '<html><head><title>%s</title></head><body>%s</body></html>',
            fake()->sentence(),
            implode("\n", $paragraphs)
        );
    }

    private function createPlaceholderFiles(array $fileIds): void
    {
        $this->command->info('Creating placeholder files on disk...');

        $count = 0;
        $batchSize = 10;
        $chunks = array_chunk($fileIds, $batchSize);

        foreach ($chunks as $chunk) {
            $files = File::whereIn('id', $chunk)->get();

            foreach ($files as $file) {
                $fullPath = storage_path('app/' . $file->path);
                $directory = dirname($fullPath);

                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                file_put_contents($fullPath, 'Placeholder content for ' . $file->name);
                $count++;
            }

            unset($files);
            gc_collect_cycles();
        }

        $this->command->info("Created {$count} placeholder files.");
    }
}
