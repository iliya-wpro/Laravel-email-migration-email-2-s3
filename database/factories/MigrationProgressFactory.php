<?php

namespace Database\Factories;

use App\Models\MigrationProgress;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MigrationProgressFactory extends Factory
{
    protected $model = MigrationProgress::class;

    public function definition(): array
    {
        return [
            'batch_id' => Str::uuid()->toString(),
            'last_processed_email_id' => 0,
            'total_emails' => $this->faker->numberBetween(1000, 100000),
            'processed_emails' => 0,
            'failed_emails' => 0,
            'status' => 'pending',
            'started_at' => now(),
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'processed_emails' => $this->faker->numberBetween(100, 5000),
        ]);
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $total = $attributes['total_emails'];
            return [
                'status' => 'completed',
                'processed_emails' => $total,
                'last_processed_email_id' => $total,
                'completed_at' => now(),
            ];
        });
    }
}
