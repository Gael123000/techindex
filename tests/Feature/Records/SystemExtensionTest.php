<?php

namespace Tests\Feature\Records;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\CommittedDataTestCase;
use Tests\Concerns\InteractsWithRecordBuilder;

/**
 * Champs ajoutés aux fiches par défaut (ici les équipements) et reprise
 * des anciens champs personnalisés.
 */
class SystemExtensionTest extends CommittedDataTestCase
{
    use InteractsWithRecordBuilder;

    private Client $client;

    private User $manager;

    private RecordType $equipmentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropRecordTables();
        $this->client = Client::factory()->create();
        $this->manager = $this->userFor($this->client, UserRole::Manager);
        $this->equipmentType = RecordType::query()->where('entity', 'equipment')->sole();
    }

    protected function tearDown(): void
    {
        $this->dropRecordTables();
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');

        parent::tearDown();
    }

    public function test_fields_added_to_equipment_follow_the_equipment_type(): void
    {
        $switch = EquipmentType::query()->where('slug', 'switch')->sole();
        $server = EquipmentType::query()->where('slug', 'physical-server')->sole();

        $this->actingAs($this->admin())->post("/admin/record-types/{$this->equipmentType->id}/fields", [
            'label' => 'Nombre de ports', 'key' => 'ports', 'type' => 'integer', 'is_required' => true,
            'visibility' => ['field' => 'equipment_type_id', 'operator' => 'equals', 'value' => [(string) $switch->id]],
        ])->assertSessionHasNoErrors();
        $this->addRecordField($this->equipmentType, 'poe', 'boolean', ['label' => 'PoE']);
        $this->assertTrue(Schema::hasTable('cx_ext_equipment'));

        $url = "/clients/{$this->client->id}/equipment";
        $payload = ['equipment_type_id' => $switch->id, 'name' => 'SW-01', 'status' => 'in_service', 'fields' => ['ports' => 'quarante-huit', 'poe' => true]];

        $this->actingAs($this->manager)->post($url, $payload)->assertSessionHasErrors('fields.ports');

        $payload['fields']['ports'] = 48;
        $this->actingAs($this->manager)->post($url, $payload)->assertSessionHasNoErrors();
        $equipment = Equipment::query()->where('name', 'SW-01')->sole();
        $row = DB::table('cx_ext_equipment')->where('record_id', $equipment->id)->first();
        $this->assertSame(48, (int) $row->ports);
        $this->assertSame(1, (int) $row->poe);

        // Un serveur n'a pas de ports : champ masqué, ni obligatoire ni enregistré.
        $this->actingAs($this->manager)->post($url, ['equipment_type_id' => $server->id, 'name' => 'SRV-01', 'status' => 'in_service', 'fields' => ['ports' => 12]])
            ->assertSessionHasNoErrors();
        $this->assertNull(DB::table('cx_ext_equipment')->where('record_id', Equipment::query()->where('name', 'SRV-01')->value('id'))->value('ports'));

        // Modification : une seule entrée d'historique avec le champ ajouté.
        $payload['fields']['ports'] = 24;
        $this->actingAs($this->manager)->put("{$url}/{$equipment->id}", $payload)->assertSessionHasNoErrors();
        $log = AuditLog::query()->where('event', 'equipment.updated')->sole();
        $this->assertSame(['old' => '48', 'new' => '24'], $log->changes['Champ « Nombre de ports »']);

        $this->actingAs($this->manager)->get("{$url}/{$equipment->id}")
            ->assertInertia(fn ($page) => $page->where('extension.0.fields.0.display', '24'));
        $this->actingAs($this->manager)->get("{$url}/{$equipment->id}/edit")
            ->assertInertia(fn ($page) => $page->where('extension.values.ports', 24)->where('extension.values.poe', true));

        // Suppression de l'équipement : sa ligne d'extension disparaît avec lui.
        $this->actingAs($this->manager)->delete("{$url}/{$equipment->id}", ['confirmation' => 'SW-01'])->assertRedirect();
        $this->assertSame(0, DB::table('cx_ext_equipment')->where('record_id', $equipment->id)->count());
    }

    public function test_equipment_without_added_fields_is_unchanged(): void
    {
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/equipment", [
            'equipment_type_id' => EquipmentType::query()->value('id'), 'name' => 'PC-01', 'status' => 'in_service',
        ])->assertSessionHasNoErrors();

        $equipment = Equipment::query()->sole();
        $this->assertFalse(Schema::hasTable('cx_ext_equipment'));
        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/equipment/{$equipment->id}")->assertInertia(fn ($page) => $page->where('extension', null));
    }

    public function test_every_default_fiche_accepts_added_fields(): void
    {
        $site = $this->client->sites()->create(['name' => 'Siège']);
        $base = "/clients/{$this->client->id}";

        // Fiche => [URL de création, données minimales, URL d'affichage (null : fenêtre), table].
        $cases = [
            'site' => ["{$base}/sites", ['name' => 'Entrepôt'], 'sites'],
            'contact' => ["{$base}/contacts", ['last_name' => 'Durand'], 'contacts'],
            'subnet' => ["{$base}/subnets", ['name' => 'LAN', 'cidr' => '10.0.0.0/24'], 'subnets'],
            'service' => ["{$base}/services", ['category' => 'vpn', 'name' => 'VPN', 'status' => 'active'], 'services'],
            'license' => ["{$base}/licenses", ['kind' => 'license', 'name' => 'Antivirus', 'status' => 'active'], 'licenses'],
            'certificate' => ["{$base}/certificates", ['name' => 'Certificat', 'type' => 'public', 'status' => 'active'], 'certificates'],
            'document' => ["{$base}/documents", ['title' => 'Procédure', 'status' => 'published'], 'documents'],
            'internet_link' => ["{$base}/internet-links", ['operator_name' => 'Opérateur', 'name' => 'Fibre', 'role' => 'primary', 'status' => 'active'], 'internet_links'],
        ];

        foreach ($cases as $entity => [$url, $payload, $table]) {
            $type = RecordType::query()->where('entity', $entity)->sole();
            $this->addRecordField($type, 'reference_interne', 'text', ['label' => 'Référence interne', 'is_required' => true]);
            $this->addRecordField($type, 'site_lie', 'reference', ['label' => 'Site lié', 'options' => ['target' => 'site']]);

            $this->actingAs($this->manager)->post($url, $payload)->assertSessionHasErrors('fields.reference_interne');
            $this->actingAs($this->manager)->post($url, [...$payload, 'fields' => ['reference_interne' => 'REF-'.$entity, 'site_lie' => $site->id]])
                ->assertSessionHasNoErrors();

            $id = DB::table($table)->where('client_id', $this->client->id)->max('id');
            $row = DB::table("cx_ext_{$entity}")->where('record_id', $id)->first();
            $this->assertSame('REF-'.$entity, $row?->reference_interne, "Fiche {$entity}");
            $this->assertSame($site->id, (int) $row->site_lie);
        }

        // Fiche client : formulaire et page du client.
        $clientType = RecordType::query()->where('entity', 'client')->sole();
        $this->addRecordField($clientType, 'code_comptable', 'text', ['label' => 'Code comptable']);
        $this->actingAs($this->admin())->put("{$base}", [
            'name' => $this->client->name, 'reference' => $this->client->reference, 'fields' => ['code_comptable' => 'C-411'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('C-411', DB::table('cx_ext_client')->where('record_id', $this->client->id)->value('code_comptable'));
        $this->actingAs($this->manager)->get($base)->assertInertia(fn ($page) => $page->where('extension.0.fields.0.display', 'C-411'));
        $this->assertSame(1, AuditLog::query()->where('event', 'client.updated')->count());
    }

    public function test_condition_on_a_builtin_field_must_exist(): void
    {
        $this->actingAs($this->admin())->post("/admin/record-types/{$this->equipmentType->id}/fields", [
            'label' => 'Champ', 'key' => 'champ', 'type' => 'text',
            'visibility' => ['field' => 'champ_inexistant', 'operator' => 'equals', 'value' => ['1']],
        ])->assertSessionHasErrors('visibility.field');
    }

    public function test_legacy_custom_fields_are_moved_to_the_builder_with_their_values(): void
    {
        $switch = EquipmentType::query()->where('slug', 'switch')->sole();
        $this->createLegacyTables();

        $ports = DB::table('custom_fields')->insertGetId(['equipment_type_id' => $switch->id, 'key' => 'ports', 'label' => 'Nombre de ports', 'type' => 'number', 'is_required' => true, 'sort_order' => 1]);
        $poe = DB::table('custom_fields')->insertGetId(['equipment_type_id' => null, 'key' => 'poe', 'label' => 'PoE', 'type' => 'boolean', 'sort_order' => 2]);
        $role = DB::table('custom_fields')->insertGetId(['equipment_type_id' => null, 'key' => 'id', 'label' => 'Rôle réseau', 'type' => 'select', 'options' => json_encode(['Cœur', 'Accès']), 'sort_order' => 3]);
        $equipment = Equipment::factory()->for($this->client)->create(['equipment_type_id' => $switch->id]);

        DB::table('custom_field_values')->insert(['custom_field_id' => $ports, 'equipment_id' => $equipment->id, 'value_number' => 48]);
        DB::table('custom_field_values')->insert(['custom_field_id' => $poe, 'equipment_id' => $equipment->id, 'value_boolean' => true]);
        DB::table('custom_field_values')->insert(['custom_field_id' => $role, 'equipment_id' => $equipment->id, 'value_text' => 'Cœur']);

        (require database_path('migrations/2026_10_15_300100_move_equipment_custom_fields_to_record_builder.php'))->up();

        $this->assertFalse(Schema::hasTable('custom_fields'));
        $this->assertFalse(Schema::hasTable('custom_field_values'));

        $fields = RecordField::query()->where('record_type_id', $this->equipmentType->id)->get()->keyBy('label');
        $this->assertSame('decimal', $fields['Nombre de ports']->type->value);
        $this->assertSame(['field' => 'equipment_type_id', 'operator' => 'equals', 'value' => [(string) $switch->id]], $fields['Nombre de ports']->visibility);
        $this->assertNull($fields['PoE']->visibility);
        $this->assertSame(['Cœur', 'Accès'], $fields['Rôle réseau']->choices());
        // « id » est réservé : la colonne reçoit un autre nom.
        $this->assertNotSame('id', $fields['Rôle réseau']->column_name);

        $row = DB::table('cx_ext_equipment')->where('record_id', $equipment->id)->first();
        $this->assertEquals(48, (float) $row->{$fields['Nombre de ports']->column_name});
        $this->assertSame(1, (int) $row->{$fields['PoE']->column_name});
        $this->assertSame('Cœur', $row->{$fields['Rôle réseau']->column_name});
    }

    private function createLegacyTables(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 120);
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('help_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->timestamps();
        });
    }
}
