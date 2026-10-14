<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('document_categories')->nullOnDelete();
            $table->string('title');
            // HTML nettoyé côté serveur (App\Services\Documents\HtmlSanitizer).
            $table->longText('content_html')->nullable();
            // Texte brut dérivé du HTML, utilisé pour la recherche et les extraits.
            $table->longText('content_text')->nullable();
            // draft | published | archived
            $table->string('status', 20)->default('published');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('review_interval_days')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('last_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->fullText(['title', 'content_text'], 'documents_search_fulltext');
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('content_html')->nullable();
            $table->string('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'version']);
        });

        Schema::create('document_equipment', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();

            $table->primary(['document_id', 'equipment_id']);
        });

        Schema::create('document_service', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->primary(['document_id', 'service_id']);
        });

        // Pièces jointes stockées sur un disque privé, sous un nom généré.
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 40);
            $table->unsignedBigInteger('attachable_id');
            $table->string('original_name');
            $table->string('stored_path')->unique();
            $table->string('mime_type', 150);
            $table->string('extension', 20);
            $table->unsignedBigInteger('size');
            $table->char('checksum_sha256', 64);
            $table->string('description')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('document_service');
        Schema::dropIfExists('document_equipment');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
