<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dossiers du gestionnaire de mots de passe et de l'explorateur de
        // fichiers. La racine de chaque client est implicite (parent_id NULL) :
        // elle existe dès que le client existe, sans synchronisation.
        foreach (['vault_folders', 'file_folders'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained($name)->cascadeOnDelete();
                $table->string('name', 100);
                // Clé d'unicité : la racine (parent NULL) devient 0, afin que deux
                // dossiers frères ne puissent pas porter le même nom (casse ignorée).
                $table->unsignedBigInteger('parent_key')->storedAs('coalesce(`parent_id`, 0)');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['client_id', 'parent_key', 'name']);
            });
        }

        // Un accès reste rattaché à son équipement ou service : le dossier est
        // un rangement supplémentaire dans le gestionnaire de mots de passe.
        Schema::table('credentials', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('service_id')->constrained('vault_folders')->nullOnDelete();
        });

        // Une pièce jointe reste rattachée à son élément (équipement, site...) :
        // le dossier est son emplacement dans l'explorateur de fichiers.
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('attachable_id')->constrained('file_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::table('credentials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('file_folders');
        Schema::dropIfExists('vault_folders');
    }
};
