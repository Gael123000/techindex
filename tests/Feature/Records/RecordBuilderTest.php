<?php

namespace Tests\Feature\Records;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CustomRecord;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\CommittedDataTestCase;
use Tests\Concerns\InteractsWithRecordBuilder;

/**
 * Constructeur de fiches (administration) : tables et colonnes créées,
 * noms contrôlés, secrets refusés, disposition, suppression.
 */
class RecordBuilderTest extends CommittedDataTestCase
{
    use InteractsWithRecordBuilder;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropRecordTables();
        $this->admin = $this->admin();
    }

    protected function tearDown(): void
    {
        $this->dropRecordTables();

        parent::tearDown();
    }

    public function test_only_administrators_manage_fiches(): void
    {
        $manager = User::factory()->manager()->create();
        $type = $this->createRecordType();

        $this->actingAs($manager)->get('/admin/record-types')->assertForbidden();
        $this->actingAs($manager)->get("/admin/record-types/{$type->id}")->assertForbidden();
        $this->actingAs($manager)->post('/admin/record-types', ['name' => 'X', 'plural_name' => 'X', 'icon' => 'box', 'table' => 'xx'])->assertForbidden();
        $this->actingAs($manager)->post("/admin/record-types/{$type->id}/fields", ['label' => 'Champ', 'key' => 'champ', 'type' => 'text'])->assertForbidden();
        $this->assertFalse(Schema::hasColumn('cx_contrats', 'champ'));
    }

    public function test_creating_a_fiche_creates_its_table_and_title_field(): void
    {
        $this->actingAs($this->admin)->post('/admin/record-types', [
            'name' => 'Contrat de maintenance', 'plural_name' => 'Contrats de maintenance', 'icon' => 'handshake',
            'table' => 'contrats_maintenance', 'title_label' => 'Référence', 'tab_after' => 'licenses',
            'settings' => ['has_site' => true, 'has_tags' => false, 'has_attachments' => true],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $type = RecordType::query()->where('key', 'contrats-maintenance')->sole();
        $this->assertSame('cx_contrats_maintenance', $type->table_name);
        $this->assertFalse($type->is_system);
        $this->assertSame(['record_id', 'nom'], Schema::getColumnListing('cx_contrats_maintenance'));
        $this->assertSame('Référence', $type->fields()->sole()->label);
        $this->assertTrue($type->fields()->sole()->is_title);
        $this->assertDatabaseHas('audit_logs', ['event' => 'record_type.created', 'subject_id' => $type->id]);

        // Nouvel onglet dans chaque client, après « Licences ».
        $client = Client::factory()->create();
        $this->actingAs($this->admin)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page->where('context.custom_tabs.0.key', 'contrats-maintenance')->where('context.custom_tabs.0.after', 'licenses'));

        $this->actingAs($this->admin)->get("/admin/record-types/{$type->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/RecordTypes/Builder')->where('database.table', 'cx_contrats_maintenance')->has('fields', 1));
    }

    public function test_table_names_are_strictly_controlled(): void
    {
        $this->createRecordType('contrats');
        $payload = ['name' => 'Fiche', 'plural_name' => 'Fiches', 'icon' => 'box'];

        foreach (['contrats', 'ext_site', 'a', '1abc', 'x; DROP TABLE users', 'Majuscules', 'tiret-bas', 'a__b', str_repeat('a', 41)] as $table) {
            $this->actingAs($this->admin)->post('/admin/record-types', [...$payload, 'table' => $table])->assertSessionHasErrors('table');
        }

        // Un nom qui existe parmi les tables de TechIndex reste préfixé : la table créée est cx_users, jamais users.
        $this->actingAs($this->admin)->post('/admin/record-types', [...$payload, 'table' => 'users'])->assertSessionHasNoErrors();
        $this->assertTrue(Schema::hasTable('cx_users'));
        $this->assertSame(2, RecordType::query()->custom()->count());
    }

    public function test_column_names_are_strictly_controlled_and_secrets_refused(): void
    {
        $type = $this->createRecordType();
        $url = "/admin/record-types/{$type->id}/fields";

        foreach (['id', 'record_id', 'client_id', 'nom', 'Nom', '1abc', 'a-b', 'x`y', 'x y'] as $key) {
            $this->actingAs($this->admin)->post($url, ['label' => 'Champ', 'key' => $key, 'type' => 'text'])->assertSessionHasErrors('key');
        }

        foreach (['Mot de passe Wi-Fi', 'Clé API', 'Code PIN du badge', 'Token GitHub', 'Clé de licence'] as $label) {
            $this->actingAs($this->admin)->post($url, ['label' => $label, 'key' => 'valeur', 'type' => 'text'])->assertSessionHasErrors('label');
        }

        $this->assertSame(['record_id', 'nom'], Schema::getColumnListing('cx_contrats'));
    }

    public function test_each_field_creates_a_typed_column(): void
    {
        $type = $this->createRecordType();
        $url = "/admin/record-types/{$type->id}/fields";

        $fields = [
            ['label' => 'Montant', 'key' => 'montant', 'type' => 'currency'],
            ['label' => 'Postes couverts', 'key' => 'postes', 'type' => 'integer', 'options' => ['min' => 1, 'max' => 500, 'unit' => 'postes']],
            ['label' => 'Niveau', 'key' => 'niveau', 'type' => 'select', 'options' => ['choices' => ['Bronze', 'Argent', 'Or']]],
            ['label' => 'Options', 'key' => 'options_contrat', 'type' => 'multiselect', 'options' => ['choices' => ['Sur site', 'Astreinte']]],
            ['label' => 'Échéance', 'key' => 'echeance', 'type' => 'date'],
            ['label' => 'Reconduction', 'key' => 'reconduction', 'type' => 'boolean'],
            ['label' => 'Site principal', 'key' => 'site_principal', 'type' => 'reference', 'options' => ['target' => 'site']],
            ['label' => 'Référent', 'key' => 'referent', 'type' => 'user'],
        ];

        foreach ($fields as $field) {
            $this->actingAs($this->admin)->post($url, $field)->assertSessionHasNoErrors();
        }

        $columns = collect(Schema::getColumns('cx_contrats'))->keyBy('name');
        $this->assertStringStartsWith('decimal(14,2)', strtolower($columns['montant']['type']));
        $this->assertStringStartsWith('bigint', strtolower($columns['postes']['type']));
        $this->assertStringStartsWith('varchar(150)', strtolower($columns['niveau']['type']));
        $this->assertSame('date', strtolower($columns['echeance']['type']));
        $this->assertStringStartsWith('tinyint(1)', strtolower($columns['reconduction']['type']));
        $this->assertStringContainsString('bigint', strtolower($columns['site_principal']['type']));
        $this->assertTrue((bool) $columns['montant']['nullable']);

        // Clés étrangères des liens : effacées si l'élément visé disparaît.
        $foreign = collect(Schema::getForeignKeys('cx_contrats'))->keyBy(fn ($key) => $key['columns'][0]);
        $this->assertSame('sites', $foreign['site_principal']['foreign_table']);
        $this->assertSame('users', $foreign['referent']['foreign_table']);
        $this->assertSame('custom_records', $foreign['record_id']['foreign_table']);
    }

    public function test_a_reference_must_target_a_known_fiche(): void
    {
        $type = $this->createRecordType();

        $this->actingAs($this->admin)->post("/admin/record-types/{$type->id}/fields", [
            'label' => 'Lien', 'key' => 'lien', 'type' => 'reference', 'options' => ['target' => 'users'],
        ])->assertSessionHasErrors('options.target');

        $this->actingAs($this->admin)->post("/admin/record-types/{$type->id}/fields", [
            'label' => 'Contrat parent', 'key' => 'parent', 'type' => 'reference', 'options' => ['target' => 'record:'.$type->id],
        ])->assertSessionHasNoErrors();
    }

    public function test_type_change_is_refused_when_values_would_be_lost(): void
    {
        $type = $this->createRecordType();
        $field = $this->addRecordField($type, 'commentaire', 'text');
        $client = Client::factory()->create();
        $record = $this->record($type, $client, ['nom' => 'C-1', 'commentaire' => 'Texte saisi']);
        $url = "/admin/record-types/{$type->id}/fields/{$field->id}";

        // Texte → nombre : perte de données, refusé.
        $this->actingAs($this->admin)->put($url, ['label' => 'Commentaire', 'type' => 'integer'])->assertSessionHasErrors('type');

        // Texte → texte long : conversion sans perte.
        $this->actingAs($this->admin)->put($url, ['label' => 'Commentaire', 'type' => 'textarea'])->assertSessionHasNoErrors();
        $this->assertSame('text', strtolower(collect(Schema::getColumns('cx_contrats'))->firstWhere('name', 'commentaire')['type']));
        $this->assertSame('Texte saisi', DB::table('cx_contrats')->where('record_id', $record->id)->value('commentaire'));

        // Le nom technique est figé.
        $this->actingAs($this->admin)->put($url, ['label' => 'Commentaire', 'type' => 'textarea', 'key' => 'autre'])->assertSessionHasErrors('key');
    }

    public function test_deleting_a_field_drops_its_column_after_confirmation(): void
    {
        $type = $this->createRecordType();
        $field = $this->addRecordField($type, 'commentaire', 'text');
        $title = $type->fields()->where('is_title', true)->sole();
        $url = "/admin/record-types/{$type->id}/fields/{$field->id}";

        $this->actingAs($this->admin)->delete($url, ['confirmation' => 'autre'])->assertSessionHasErrors('confirmation');
        $this->assertTrue(Schema::hasColumn('cx_contrats', 'commentaire'));

        $this->actingAs($this->admin)->delete($url, ['confirmation' => 'Commentaire'])->assertSessionHasNoErrors();
        $this->assertFalse(Schema::hasColumn('cx_contrats', 'commentaire'));
        $this->assertNull(RecordField::query()->find($field->id));
        $this->assertDatabaseHas('audit_logs', ['event' => 'record_field.deleted', 'subject_id' => $field->id]);

        // Le champ titre ne peut pas être supprimé.
        $this->actingAs($this->admin)->delete("/admin/record-types/{$type->id}/fields/{$title->id}", ['confirmation' => 'Référence'])->assertSessionHasErrors('field');
        $this->assertTrue(Schema::hasColumn('cx_contrats', 'nom'));
    }

    public function test_layout_is_saved(): void
    {
        $type = $this->createRecordType();
        $a = $this->addRecordField($type, 'alpha', 'text');
        $b = $this->addRecordField($type, 'beta', 'text');
        $title = $type->fields()->where('is_title', true)->sole();

        $this->actingAs($this->admin)->put("/admin/record-types/{$type->id}/layout", [
            'sections' => [
                ['key' => 'general', 'title' => 'Général', 'columns' => 3],
                ['key' => 'details', 'title' => 'Détails', 'description' => 'Compléments', 'columns' => 1],
            ],
            'fields' => [
                ['id' => $b->id, 'section' => 'general', 'width' => 'full'],
                ['id' => $title->id, 'section' => 'general', 'width' => '2'],
                ['id' => $a->id, 'section' => 'details', 'width' => '1'],
            ],
        ])->assertSessionHasNoErrors();

        $type->refresh();
        $this->assertSame(['general', 'details'], array_column($type->sectionList(), 'key'));
        $this->assertSame(3, $type->sectionList()[0]['columns']);
        $this->assertSame(['beta', 'nom', 'alpha'], $type->fields()->get()->sortBy(fn ($field) => [$field->section === 'details', $field->sort_order])->pluck('key')->values()->all());
        $this->assertSame('details', $a->fresh()->section);
        $this->assertSame('full', $b->fresh()->width);

        // Un champ d'une autre fiche est refusé.
        $other = $this->createRecordType('autres');
        $this->actingAs($this->admin)->put("/admin/record-types/{$type->id}/layout", [
            'sections' => [['key' => 'general', 'title' => 'Général', 'columns' => 2]],
            'fields' => [['id' => $other->fields()->sole()->id, 'section' => 'general', 'width' => '1']],
        ])->assertSessionHasErrors('fields.0.id');
    }

    public function test_deleting_a_fiche_drops_its_table_unless_another_fiche_points_to_it(): void
    {
        $type = $this->createRecordType();
        $other = $this->createRecordType('interventions', ['name' => 'Intervention', 'plural_name' => 'Interventions']);
        $reference = $this->addRecordField($other, 'contrat', 'reference', ['options' => ['target' => 'record:'.$type->id]]);
        $client = Client::factory()->create();
        $record = $this->record($type, $client, ['nom' => 'C-1']);

        $this->actingAs($this->admin)->delete("/admin/record-types/{$type->id}", ['confirmation' => 'Contrat'])->assertSessionHasErrors('type');
        $this->assertTrue(Schema::hasTable('cx_contrats'));

        $this->actingAs($this->admin)->delete("/admin/record-types/{$other->id}/fields/{$reference->id}", ['confirmation' => 'Contrat']);
        $this->actingAs($this->admin)->delete("/admin/record-types/{$type->id}", ['confirmation' => 'mauvais'])->assertSessionHasErrors('confirmation');
        $this->actingAs($this->admin)->delete("/admin/record-types/{$type->id}", ['confirmation' => 'Contrat'])->assertRedirect('/admin/record-types');

        $this->assertFalse(Schema::hasTable('cx_contrats'));
        $this->assertNull(CustomRecord::query()->find($record->id));
        $this->assertNull(RecordType::query()->find($type->id));
    }

    public function test_default_fiches_cannot_be_deleted_or_renamed(): void
    {
        $equipment = RecordType::query()->where('entity', 'equipment')->sole();

        $this->actingAs($this->admin)->delete("/admin/record-types/{$equipment->id}", ['confirmation' => $equipment->name])->assertSessionHasErrors('type');
        $this->actingAs($this->admin)->put("/admin/record-types/{$equipment->id}", ['name' => 'Autre', 'plural_name' => 'Autres', 'icon' => 'box'])->assertSessionHas('error');
        $this->assertSame('Équipement', $equipment->fresh()->name);
        $this->assertDatabaseCount('record_types', 10 + RecordType::query()->custom()->count());
    }

    public function test_builder_pages_render(): void
    {
        $type = $this->createRecordType();
        $equipment = RecordType::query()->where('entity', 'equipment')->sole();

        $this->actingAs($this->admin)->get('/admin/record-types')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/RecordTypes/Index')->has('types', 11));
        $this->actingAs($this->admin)->get("/admin/record-types/{$equipment->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/RecordTypes/Builder')->where('recordType.is_system', true)->has('builtin')->has('conditionSources.equipment_type_id'));
        $this->actingAs($this->admin)->get("/admin/record-types/{$type->id}")->assertOk();
        $this->actingAs($this->admin)->get('/admin/custom-fields')->assertRedirect('/admin/record-types');
        $this->assertSame(0, AuditLog::query()->where('event', 'record_type.updated')->count());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function record(RecordType $type, Client $client, array $values): CustomRecord
    {
        $record = new CustomRecord(['status' => 'active']);
        $record->forceFill(['record_type_id' => $type->id, 'title' => (string) ($values['nom'] ?? 'Sans titre')]);
        $record->client()->associate($client);
        $record->save();
        DB::table($type->table_name)->insert(['record_id' => $record->id, ...$values]);

        return $record;
    }
}
