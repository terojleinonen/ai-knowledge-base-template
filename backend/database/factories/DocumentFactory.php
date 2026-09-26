<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->slug(3).'.txt';

        return [
            'user_id' => User::factory(),
            'title' => pathinfo($name, PATHINFO_FILENAME),
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size_bytes' => fake()->numberBetween(100, 10_000),
            'disk' => 'local',
            'path' => 'documents/'.fake()->uuid().'.txt',
            'checksum' => hash('sha256', fake()->uuid()),
            'status' => DocumentStatus::Pending,
        ];
    }

    public function ready(int $chunks = 1): static
    {
        return $this->state([
            'status' => DocumentStatus::Ready,
            'chunk_count' => $chunks,
            'processed_at' => now(),
        ]);
    }
}
