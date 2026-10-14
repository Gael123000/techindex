<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clés d'accès (WebAuthn / FIDO2) : Windows Hello, Touch ID, téléphone,
        // clé de sécurité USB ou NFC. Seule la clé PUBLIQUE est conservée
        // (colonne "credential") : la clé privée ne quitte jamais l'appareil.
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // Identifiant de la clé (base64url), unique sur toute l'application.
            $table->string('credential_id')->unique();
            // Enregistrement WebAuthn : clé publique COSE, compteur de signatures, AAGUID.
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
