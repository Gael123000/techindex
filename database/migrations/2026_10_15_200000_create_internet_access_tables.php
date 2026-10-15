<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogue des fournisseurs d'accès à Internet (FAI), commun à tous les clients.
        Schema::create('isps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            // Code pays ISO 3166-1 alpha-2 ; nul pour un opérateur international.
            $table->string('country', 2)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('customer_area_url', 500)->nullable();
            $table->string('status_url', 500)->nullable();
            $table->string('support_phone', 50)->nullable();
            $table->string('business_support_phone', 50)->nullable();
            $table->string('support_email', 255)->nullable();
            // Logo fourni avec TechIndex (database/data/isp-logos) ou déposé par un administrateur (disque privé).
            $table->string('bundled_logo', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        // Liens d'accès à Internet d'un client.
        Schema::create('internet_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // Un opérateur du catalogue ne peut pas être supprimé tant qu'il est utilisé.
            $table->foreignId('isp_id')->nullable()->constrained()->restrictOnDelete();
            // Opérateur absent du catalogue.
            $table->string('operator_name', 120)->nullable();
            $table->string('name');
            // primary | backup | aggregated | other (App\Enums\InternetLinkRole)
            $table->string('role', 20)->default('primary');
            // App\Enums\InternetTechnology
            $table->string('technology', 20)->nullable();
            // active | pending | terminated | archived (App\Enums\InternetLinkStatus)
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('download_mbps')->nullable();
            $table->unsignedInteger('upload_mbps')->nullable();
            $table->unsignedInteger('guaranteed_mbps')->nullable();
            $table->string('customer_number', 120)->nullable();
            $table->string('contract_reference', 150)->nullable();
            $table->string('line_reference', 150)->nullable();
            // dhcp | pppoe | static | other (App\Enums\InternetConnectionMode)
            $table->string('connection_mode', 20)->nullable();
            // Identifiant de connexion (PPPoE...) ; le mot de passe va dans un accès sécurisé.
            $table->string('connection_username')->nullable();
            $table->unsignedSmallInteger('wan_vlan')->nullable();
            $table->string('public_ips', 500)->nullable();
            $table->string('wan_gateway', 45)->nullable();
            $table->string('dns_servers')->nullable();
            // Garantie de temps de rétablissement.
            $table->string('gtr', 120)->nullable();
            $table->string('support_phone', 50)->nullable();
            $table->date('commissioned_at')->nullable();
            $table->date('commitment_ends_at')->nullable()->index();
            $table->decimal('monthly_cost', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('line_reference');
            $table->fullText(['name', 'operator_name', 'description', 'notes'], 'internet_links_search_fulltext');
        });

        // Équipements de terminaison et de routage (box, routeur, pare-feu...).
        Schema::create('equipment_internet_link', function (Blueprint $table) {
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('internet_link_id')->constrained()->cascadeOnDelete();

            $table->primary(['equipment_id', 'internet_link_id']);
        });

        Schema::create('document_internet_link', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('internet_link_id')->constrained()->cascadeOnDelete();

            $table->primary(['document_id', 'internet_link_id']);
        });

        // Accès (espace client de l'opérateur, identifiants PPPoE...) rattachés à un lien.
        Schema::table('credentials', function (Blueprint $table) {
            $table->foreignId('internet_link_id')->nullable()->after('service_id')->constrained()->cascadeOnDelete();
        });

        // Usage du sous-réseau (LAN, WAN...) et lien Internet qui le dessert.
        Schema::table('subnets', function (Blueprint $table) {
            $table->string('purpose', 20)->nullable()->after('vlan_id');
            $table->foreignId('internet_link_id')->nullable()->after('purpose')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('internet_link_id');
            $table->dropColumn('purpose');
        });

        Schema::table('credentials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('internet_link_id');
        });

        Schema::dropIfExists('document_internet_link');
        Schema::dropIfExists('equipment_internet_link');
        Schema::dropIfExists('internet_links');
        Schema::dropIfExists('isps');
    }
};
