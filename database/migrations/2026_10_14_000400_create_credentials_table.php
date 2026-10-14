<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accès et identifiants. Le secret n'est jamais stocké en clair :
        // secret_ciphertext contient la charge chiffrée (AES-256-GCM) et
        // secret_key_id l'empreinte de la clé utilisée (rotation possible).
        // Aucun index plein texte sur cette table : les secrets ne sont pas recherchables.
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('label', 150);
            // web | ssh | rdp | api | vpn | database | snmp | other
            $table->string('access_type', 20);
            $table->string('username')->nullable();
            $table->text('secret_ciphertext')->nullable();
            $table->string('secret_key_id', 32)->nullable();
            $table->timestamp('secret_updated_at')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('client_id');
            $table->index('secret_key_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
