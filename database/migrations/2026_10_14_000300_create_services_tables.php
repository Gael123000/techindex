<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Services documentés : Internet, VPN, domaines, hébergement, cloud,
        // messagerie, applications métiers, sauvegardes...
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // App\Enums\ServiceCategory
            $table->string('category', 30)->index();
            $table->string('name');
            $table->string('provider', 150)->nullable();
            $table->string('reference', 150)->nullable();
            $table->string('url', 500)->nullable();
            // active | inactive | archived
            $table->string('status', 20)->default('active');
            $table->date('start_date')->nullable();
            // Échéance du contrat, renouvellement du domaine...
            $table->date('end_date')->nullable()->index();
            // Champs structurés propres à la catégorie, définis et validés par
            // App\Enums\ServiceCategory::fields().
            $table->json('details')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'category']);
            $table->fullText(['name', 'provider', 'reference', 'description'], 'services_search_fulltext');
        });

        Schema::create('equipment_service', function (Blueprint $table) {
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->primary(['equipment_id', 'service_id']);
        });

        // Licences et abonnements.
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // license | subscription | support
            $table->string('kind', 20)->default('license');
            $table->string('name');
            $table->string('publisher', 150)->nullable();
            $table->string('supplier', 150)->nullable();
            $table->string('contract_reference', 150)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            // monthly | yearly | multi_year | perpetual | other
            $table->string('billing_period', 20)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->boolean('auto_renewal')->default(false);
            $table->unsignedSmallInteger('renewal_notice_days')->nullable();
            $table->text('renewal_notes')->nullable();
            // active | cancelled | archived
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('equipment_license', function (Blueprint $table) {
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();

            $table->primary(['equipment_id', 'license_id']);
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('common_name')->nullable();
            $table->text('subject_alt_names')->nullable();
            $table->string('issuer', 150)->nullable();
            // public | internal | self_signed | wildcard
            $table->string('type', 20)->default('public');
            $table->string('serial_number', 150)->nullable();
            $table->string('fingerprint_sha256', 120)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->boolean('auto_renewal')->default(false);
            $table->text('renewal_method')->nullable();
            // active | revoked | archived
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('certificate_equipment', function (Blueprint $table) {
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();

            $table->primary(['certificate_id', 'equipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_equipment');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('equipment_license');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('equipment_service');
        Schema::dropIfExists('services');
    }
};
