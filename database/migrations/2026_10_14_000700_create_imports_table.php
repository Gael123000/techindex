<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Client cible pour un import d'équipements.
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            // clients | equipment
            $table->string('type', 20);
            // create | update | upsert
            $table->string('mode', 20)->default('create');
            // uploaded | validated | queued | processing | completed | failed
            $table->string('status', 20)->default('uploaded');
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->boolean('skip_invalid_rows')->default(false);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            // Rapport ligne par ligne (erreurs, doublons, action prévue).
            $table->json('report')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
