<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Données nécessaires au fonctionnement uniquement. Les données de
     * démonstration (fictives) se chargent à part : php artisan techindex:demo
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}
