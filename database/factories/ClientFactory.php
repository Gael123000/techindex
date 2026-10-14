<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'reference' => 'TST-'.fake()->unique()->numerify('#####'),
            'description' => fake()->sentence(),
            'city' => fake()->city(),
            'country' => 'France',
            'status' => RecordStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => RecordStatus::Archived, 'archived_at' => now()]);
    }
}
