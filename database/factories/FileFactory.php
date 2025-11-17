<?php

namespace Database\Factories;

use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

class FileFactory extends Factory
{
    protected $model = File::class;

    public function definition(): array
    {
        $extension = $this->faker->randomElement(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg']);
        $name = $this->faker->word() . '.' . $extension;

        return [
            'name' => $name,
            'path' => 'files/' . $this->faker->uuid() . '/' . $name,
            'size' => $this->faker->numberBetween(1024, 10485760), // 1KB to 10MB
            'type' => $this->getMimeType($extension),
            'is_migrated' => false,
        ];
    }

    private function getMimeType(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    public function migrated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_migrated' => true,
            's3_path' => 'attachments/0/' . $this->faker->word() . '.pdf',
        ]);
    }
}
