<?php

namespace Database\Factories;

use App\Enums\EquipmentStatus;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\EquipmentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'equipment_type_id' => fn (): int => EquipmentType::query()->value('id')
                ?? EquipmentType::query()->create(['name' => 'Serveur', 'slug' => 'serveur-test', 'icon' => 'server'])->id,
            'name' => 'SRV-'.strtoupper(fake()->unique()->bothify('??##')),
            'hostname' => strtolower(fake()->unique()->bothify('host-????')),
            'manufacturer' => 'Fabricant Test',
            'model' => 'Modèle '.fake()->numerify('###'),
            'serial_number' => strtoupper(fake()->unique()->bothify('SN########')),
            'status' => EquipmentStatus::InService,
        ];
    }
}
