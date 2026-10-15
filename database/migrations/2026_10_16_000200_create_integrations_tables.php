<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Intégrations avec des services externes (distributeurs, Microsoft 365,
     * consoles de sécurité...) : licences et abonnements remontés
     * automatiquement et rattachés aux clients.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            // Connecteur (clé du catalogue : microsoft365, pax8...) ; une configuration par connecteur.
            $table->string('provider', 50)->unique();
            $table->boolean('is_enabled')->default(false);
            // Réglages sans caractère secret (région, identifiant de partenaire...).
            $table->json('settings')->nullable();
            // Identifiants d'API chiffrés (AES-256-GCM, clé des secrets), jamais transmis à l'interface.
            $table->text('credentials_ciphertext')->nullable();
            $table->string('credentials_key_id', 32)->nullable();
            $table->timestamp('credentials_updated_at')->nullable();
            // manual | every_6_hours | daily
            $table->string('frequency', 20)->default('daily');
            $table->timestamp('last_synced_at')->nullable();
            // success | partial | failed
            $table->string('last_status', 20)->nullable();
            $table->string('last_message', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('credentials_key_id');
        });

        // Clients ou locataires connus du service externe, rattachés (ou non) à un client TechIndex.
        Schema::create('integration_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('name');
            $table->string('domain')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // Compte volontairement laissé de côté (pas de rapprochement proposé).
            $table->boolean('is_ignored')->default(false);
            // Compte ajouté à la main (ex. locataire Microsoft 365 après consentement).
            $table->boolean('is_manual')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('licenses_count')->default(0);
            $table->timestamps();

            $table->unique(['integration_id', 'external_id']);
            $table->index('client_id');
        });

        Schema::create('integration_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            // manual | scheduled
            $table->string('trigger', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // queued | running | success | partial | failed
            $table->string('status', 20);
            $table->json('stats')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'id']);
        });

        Schema::table('licenses', function (Blueprint $table) {
            // Licence remontée par une intégration ; détachée (manuelle) si l'intégration disparaît.
            $table->foreignId('integration_account_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
            $table->string('external_id', 191)->nullable()->after('integration_account_id');
            // Nombre de licences attribuées ou utilisées, quand le service le fournit.
            $table->unsignedInteger('assigned_quantity')->nullable()->after('quantity');
            $table->timestamp('synced_at')->nullable()->after('notes');

            $table->unique(['integration_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropUnique(['integration_account_id', 'external_id']);
            $table->dropConstrainedForeignId('integration_account_id');
            $table->dropColumn(['external_id', 'assigned_quantity', 'synced_at']);
        });

        Schema::dropIfExists('integration_runs');
        Schema::dropIfExists('integration_accounts');
        Schema::dropIfExists('integrations');
    }
};
