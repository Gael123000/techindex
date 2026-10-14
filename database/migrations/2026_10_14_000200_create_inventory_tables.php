<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Types d'équipements configurables par l'administrateur.
        Schema::create('equipment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('icon', 50)->default('box');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Champs personnalisés typés, communs à tous les types ou propres à un type.
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 120);
            // text | number | date | boolean | select | url (App\Enums\CustomFieldType)
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('help_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['equipment_type_id', 'sort_order']);
        });

        Schema::create('vlans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'number']);
        });

        Schema::create('subnets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vlan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('cidr', 50);
            $table->string('gateway', 45)->nullable();
            $table->string('dns_servers')->nullable();
            $table->boolean('dhcp_enabled')->default(false);
            $table->string('dhcp_range')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'cidr']);
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('inventory_number', 120)->nullable();
            $table->string('location')->nullable();
            $table->string('os_name', 120)->nullable();
            $table->string('os_version', 120)->nullable();
            $table->string('firmware_version', 120)->nullable();
            $table->string('role')->nullable();
            $table->text('description')->nullable();
            // in_service | maintenance | out_of_service | archived (App\Enums\EquipmentStatus)
            $table->string('status', 20)->default('in_service');
            $table->date('purchase_date')->nullable();
            $table->date('commissioning_date')->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->date('end_of_life_at')->nullable();
            $table->string('supplier', 150)->nullable();
            $table->string('admin_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'site_id']);
            $table->index('serial_number');
            $table->index('hostname');
            $table->index('warranty_expires_at');
            $table->index('end_of_life_at');
            $table->fullText(
                ['name', 'hostname', 'manufacturer', 'model', 'serial_number', 'inventory_number', 'role', 'description'],
                'equipment_search_fulltext',
            );
        });

        // Valeurs typées des champs personnalisés : une colonne par type pour
        // permettre tri, filtre et validation (pas de texte libre unique).
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'equipment_id']);
        });

        // Interfaces réseau : plusieurs adresses IP / MAC par équipement.
        Schema::create('network_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->foreignId('subnet_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('ip_address');
            $table->index('mac_address');
        });

        // Relations entre équipements (hôte / VM, protégé par un pare-feu...).
        Schema::create('equipment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('target_equipment_id')->constrained('equipment')->cascadeOnDelete();
            // hosts | protected_by | connected_to | depends_on | backed_up_by | powered_by
            $table->string('type', 30);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['source_equipment_id', 'target_equipment_id', 'type'], 'equipment_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_links');
        Schema::dropIfExists('network_interfaces');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('subnets');
        Schema::dropIfExists('vlans');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('equipment_types');
    }
};
