<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('reference', 50)->unique();
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            // active | archived (App\Enums\RecordStatus)
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name');
            $table->fullText(['name', 'reference', 'city', 'description', 'notes'], 'clients_search_fulltext');
        });

        // Affectation des utilisateurs aux clients, avec des droits séparés.
        // La lecture est implicite dès qu'une affectation existe.
        Schema::create('client_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_export')->default(false);
            $table->boolean('can_view_secrets')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('access_instructions')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->fullText(['name', 'code', 'city', 'address_line1'], 'sites_search_fulltext');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('job_title', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->boolean('is_technical')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'last_name']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('color', 20)->default('slate');
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('taggable_type', 40);
            $table->unsignedBigInteger('taggable_id');

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('client_user');
        Schema::dropIfExists('clients');
    }
};
