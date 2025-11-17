<?php

namespace Database\Factories;

use App\Models\Email;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailFactory extends Factory
{
    protected $model = Email::class;

    public function definition(): array
    {
        return [
            'client_id' => $this->faker->numberBetween(1, 100),
            'loan_id' => $this->faker->numberBetween(1, 1000),
            'email_template_id' => $this->faker->numberBetween(1, 20),
            'receiver_email' => $this->faker->email(),
            'sender_email' => 'system@company.com',
            'subject' => $this->faker->sentence(),
            'body' => $this->generateHtmlBody(),
            'file_ids' => json_encode([]),
            'is_migrated' => false,
            'migration_attempts' => 0,
            'sent_at' => $this->faker->dateTimeBetween('-1 year'),
        ];
    }

    private function generateHtmlBody(): string
    {
        $paragraphs = [];

        for ($i = 0; $i < $this->faker->numberBetween(10, 50); $i++) {
            $paragraphs[] = '<p>' . $this->faker->paragraph($this->faker->numberBetween(5, 15)) . '</p>';
        }

        return sprintf(
            '<html><head><title>%s</title></head><body>%s</body></html>',
            $this->faker->sentence(),
            implode("\n", $paragraphs)
        );
    }

    public function migrated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_migrated' => true,
            'body_s3_path' => 'emails/0/1.html',
            'file_s3_paths' => json_encode([]),
            'migration_attempted_at' => now(),
        ]);
    }

    public function withFiles(array $fileIds): static
    {
        return $this->state(fn (array $attributes) => [
            'file_ids' => json_encode($fileIds),
        ]);
    }
}
