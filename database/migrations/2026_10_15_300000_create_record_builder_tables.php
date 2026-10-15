<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Constructeur de fiches.
 *
 * - record_types : fiches par défaut (sites, équipements...) et fiches créées
 *   par un administrateur, avec leur disposition (sections) et leurs réglages ;
 * - record_fields : champs ajoutés, chacun stocké dans une vraie colonne typée
 *   de la table de la fiche (cx_<nom>) ou de la table d'extension d'une fiche
 *   par défaut (cx_ext_<fiche>), créées par App\Services\Records\RecordSchema ;
 * - custom_records : enregistrements des fiches créées, avec un identifiant
 *   unique commun (historique, étiquettes, pièces jointes, recherche).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->boolean('is_system')->default(false);
            // Alias du modèle d'une fiche par défaut (site, equipment...).
            $table->string('entity', 40)->nullable()->unique();
            $table->string('name', 120);
            $table->string('plural_name', 120);
            $table->text('description')->nullable();
            $table->string('icon', 40)->default('clipboard-list');
            // Table des données (cx_...) ; créée à la première colonne pour une fiche par défaut.
            $table->string('table_name', 64)->unique();
            // Onglet du client après lequel apparaît la fiche.
            $table->string('tab_after', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Sections de la disposition : [{key, title, description, columns}].
            $table->json('sections')->nullable();
            // Réglages : site, étiquettes, pièces jointes, tri par défaut...
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('record_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_type_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('column_name', 64);
            $table->string('label', 120);
            // App\Enums\RecordFieldType
            $table->string('type', 20);
            // Choix, cible d'un lien, minimum, maximum, unité, valeur par défaut...
            $table->json('options')->nullable();
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_title')->default(false);
            $table->boolean('in_list')->default(false);
            $table->boolean('is_searchable')->default(false);
            // Section de la disposition, largeur et ordre.
            $table->string('section', 40)->nullable();
            $table->string('width', 10)->default('1');
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Condition d'affichage : {field, operator, value}.
            $table->json('visibility')->nullable();
            $table->timestamps();

            $table->unique(['record_type_id', 'key']);
            $table->unique(['record_type_id', 'column_name']);
        });

        Schema::create('custom_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // Titre et texte de recherche recopiés des champs de la fiche.
            $table->string('title');
            $table->text('search_text')->nullable();
            // active | archived (App\Enums\RecordStatus)
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'record_type_id', 'status']);
            $table->fullText(['title', 'search_text'], 'custom_records_search_fulltext');
        });
    }

    public function down(): void
    {
        // Les tables de données (cx_...) sont supprimées avant les métadonnées.
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if (str_starts_with($table, 'cx_')) {
                Schema::drop($table);
            }
        }

        Schema::dropIfExists('custom_records');
        Schema::dropIfExists('record_fields');
        Schema::dropIfExists('record_types');
    }
};
