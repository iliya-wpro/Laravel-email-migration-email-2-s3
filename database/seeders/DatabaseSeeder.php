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

        // Create files first
        $this->command->info('Creating 1,000 file records...');
        $files = File::factory()->count(1000)->create()->pluck('id')->toArray();
        $this->command->info('Files created successfully.');

        // Create placeholder files on disk
        $this->createPlaceholderFiles($files);

        $bar = $this->command->getOutput()->createProgressBar(100000);
        $bar->start();

        // Generate emails in chunks
        for ($i = 0; $i < 100; $i++) {
            $emails = [];

            for ($j = 0; $j < 1000; $j++) {
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
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('Seeding completed!');
        $this->command->info('Total emails: ' . number_format(Email::count()));
        $this->command->info('Total files: ' . number_format(File::count()));
    }

    private function generateHtmlBody(): string
    {
        $paragraphs = [];

        for ($i = 0; $i < rand(10, 50); $i++) {
            $paragraphs[] = '<p>' . fake()->paragraph(rand(5, 15)) . '</p>';
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

        $files = File::whereIn('id', $fileIds)->get();
        $count = 0;

        foreach ($files as $file) {
            $fullPath = storage_path('app/' . $file->path);
            $directory = dirname($fullPath);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Create a small placeholder file
            file_put_contents($fullPath, 'Placeholder content for ' . $file->name);
            $count++;
        }

        $this->command->info("Created {$count} placeholder files.");
    }
}
