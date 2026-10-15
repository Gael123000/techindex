<?php

use App\Services\Internet\LegacyInternetServiceConverter;
use App\Support\IspCatalog;
use Illuminate\Database\Migrations\Migration;

/**
 * Catalogue des FAI, puis conversion des services « Connexion Internet »
 * en liens Internet (onglet FAI). Voir LegacyInternetServiceConverter.
 */
return new class extends Migration
{
    public function up(): void
    {
        IspCatalog::sync();

        app(LegacyInternetServiceConverter::class)->convertAll();
    }

    public function down(): void
    {
        // Conversion de données : pas de retour automatique.
    }
};
