<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => 'Site '.fake()->unique()->city(),
            'city' => fake()->city(),
            'status' => RecordStatus::Active,
        ];
    }
}
