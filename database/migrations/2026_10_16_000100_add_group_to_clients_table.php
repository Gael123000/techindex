<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groupes d'entreprises : un client peut être rattaché à une entreprise
     * mère (siège, holding) comme filiale, établissement ou franchisé.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Entreprise mère ; détachement automatique si elle est supprimée.
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('clients')->nullOnDelete();
            // headquarters | holding (tête de groupe) ; subsidiary | branch | franchise (membre)
            $table->string('group_role', 20)->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('group_role');
        });
    }
};
