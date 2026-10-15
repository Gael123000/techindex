<?php

namespace Tests\Feature\Records;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CustomRecord;
use App\Models\RecordType;
use App\Models\Site;
use App\Models\User;
use App\Services\Search\GlobalSearch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\CommittedDataTestCase;
use Tests\Concerns\InteractsWithRecordBuilder;

/**
 * Enregistrements des fiches créées : droits, validation champ par champ,
 * conditions d'affichage, unicité, historique, recherche et export.
 */
class CustomRecordTest extends CommittedDataTestCase
{
    use InteractsWithRecordBuilder;

    private Client $client;

    private Client $other;

    private User $manager;

    private RecordType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropRecordTables();
        $this->client = Client::factory()->create();
        $this->other = Client::factory()->create();
        $this->manager = $this->userFor($this->client, UserRole::Manager);

        $this->type = $this->createRecordType();
        $this->addRecordField($this->type, 'postes', 'integer', ['label' => 'Postes couverts', 'is_required' => true, 'options' => ['min' => 1, 'max' => 500], 'in_list' => true]);
        $this->addRecordField($this->type, 'statut', 'select', ['label' => 'Statut', 'options' => ['choices' => ['Actif', 'Résilié']], 'in_list' => true]);
        $this->addRecordField($this->type, 'date_resiliation', 'date', [
            'label' => 'Date de résiliation', 'is_required' => true,
            'visibility' => ['field' => 'statut', 'operator' => 'equals', 'value' => ['Résilié']],
        ]);
        $this->addRecordField($this->type, 'site_principal', 'reference', ['label' => 'Site principal', 'options' => ['target' => 'site']]);
        $this->addRecordField($this->type, 'numero', 'text', ['label' => 'Numéro de contrat', 'is_unique' => true, 'is_searchable' => true]);
        $this->addRecordField($this->type, 'reconduction', 'boolean', ['label' => 'Reconduction tacite']);
    }

    protected function tearDown(): void
    {
        $this->dropRecordTables();

        parent::tearDown();
    }

    private function url(string $suffix = ''): string
    {
        return "/clients/{$this->client->id}/fiches/contrats".$suffix;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function create(array $fields, ?Client $client = null, ?User $user = null)
    {
        $client ??= $this->client;

        return $this->actingAs($user ?? $this->manager)->post("/clients/{$client->id}/fiches/contrats", ['fields' => $fields]);
    }

    public function test_record_is_created_with_typed_values(): void
    {
        $site = Site::factory()->for($this->client)->create(['name' => 'Siège']);

        $this->create(['nom' => 'CT-2026-01', 'postes' => '25', 'statut' => 'Actif', 'site_principal' => $site->id, 'numero' => 'N-1', 'reconduction' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $record = CustomRecord::query()->sole();
        $this->assertSame('CT-2026-01', $record->title);
        $this->assertSame($this->client->id, $record->client_id);
        $this->assertStringContainsString('N-1', (string) $record->search_text);

        $row = DB::table('cx_contrats')->where('record_id', $record->id)->first();
        $this->assertSame(25, (int) $row->postes);
        $this->assertSame('Actif', $row->statut);
        $this->assertSame($site->id, (int) $row->site_principal);
        $this->assertSame(1, (int) $row->reconduction);
        $this->assertNull($row->date_resiliation);

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/records/{$record->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Records/Show')
                ->where('record.title', 'CT-2026-01')
                ->where('sections.0.fields', fn ($fields) => collect($fields)->firstWhere('key', 'site_principal')['display'] === 'Siège'
                    && collect($fields)->firstWhere('key', 'reconduction')['display'] === 'Oui'
                    && collect($fields)->doesntContain('key', 'date_resiliation')));

        $this->actingAs($this->manager)->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Records/Index')->where('records.data.0.cells.postes', '25')->has('columns', 2));
    }

    public function test_values_are_validated_field_by_field(): void
    {
        $foreignSite = Site::factory()->for($this->other)->create();

        $this->create(['nom' => '', 'postes' => 'beaucoup', 'statut' => 'Inconnu', 'site_principal' => $foreignSite->id])
            ->assertSessionHasErrors(['fields.nom', 'fields.postes', 'fields.statut', 'fields.site_principal']);

        $this->create(['nom' => 'X', 'postes' => 900])->assertSessionHasErrors('fields.postes');
        $this->assertSame(0, CustomRecord::query()->count());
    }

    public function test_hidden_fields_are_neither_required_nor_saved(): void
    {
        // Statut « Actif » : la date de résiliation est masquée, donc facultative et ignorée.
        $this->create(['nom' => 'A', 'postes' => 3, 'statut' => 'Actif', 'date_resiliation' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('cx_contrats')->value('date_resiliation'));

        // Statut « Résilié » : la date devient obligatoire.
        $this->create(['nom' => 'B', 'postes' => 3, 'statut' => 'Résilié'])->assertSessionHasErrors('fields.date_resiliation');
        $this->create(['nom' => 'B', 'postes' => 3, 'statut' => 'Résilié', 'date_resiliation' => '2026-12-31'])->assertSessionHasNoErrors();
    }

    public function test_unique_values_are_scoped_to_the_client(): void
    {
        $this->create(['nom' => 'A', 'postes' => 1, 'numero' => 'N-42'])->assertSessionHasNoErrors();
        $this->create(['nom' => 'B', 'postes' => 1, 'numero' => 'N-42'])->assertSessionHasErrors('fields.numero');

        // Même numéro chez un autre client : accepté.
        $this->userFor($this->other, UserRole::Manager);
        $this->create(['nom' => 'C', 'postes' => 1, 'numero' => 'N-42'], $this->other, $this->admin())->assertSessionHasNoErrors();

        // Modifier l'enregistrement sans changer son numéro reste possible.
        $record = CustomRecord::query()->where('title', 'A')->sole();
        $this->actingAs($this->manager)->put("/clients/{$this->client->id}/records/{$record->id}", ['fields' => ['nom' => 'A', 'postes' => 2, 'numero' => 'N-42']])
            ->assertSessionHasNoErrors();
    }

    public function test_changes_are_audited_field_by_field(): void
    {
        $this->create(['nom' => 'A', 'postes' => 5, 'statut' => 'Actif'])->assertSessionHasNoErrors();
        $record = CustomRecord::query()->sole();

        $this->actingAs($this->manager)->put("/clients/{$this->client->id}/records/{$record->id}", ['fields' => ['nom' => 'A bis', 'postes' => 6, 'statut' => 'Actif']])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('event', 'custom_record.updated')->sole();
        $this->assertSame(['old' => '5', 'new' => '6'], $log->changes['Champ « Postes couverts »']);
        $this->assertSame('A bis', $log->changes['Champ « Référence »']['new']);
        $this->assertSame('A bis', $record->fresh()->title);
    }

    public function test_access_follows_client_rights(): void
    {
        $this->create(['nom' => 'A', 'postes' => 1])->assertSessionHasNoErrors();
        $record = CustomRecord::query()->sole();
        $reader = $this->userFor($this->client, UserRole::Reader);
        $stranger = $this->userFor($this->other, UserRole::Manager);

        $this->actingAs($reader)->get($this->url())->assertOk();
        $this->actingAs($reader)->get("/clients/{$this->client->id}/records/{$record->id}")->assertOk();
        $this->create(['nom' => 'B', 'postes' => 1], null, $reader)->assertForbidden();
        $this->actingAs($reader)->put("/clients/{$this->client->id}/records/{$record->id}", ['fields' => ['nom' => 'X', 'postes' => 1]])->assertForbidden();

        // Client non attribué, ou enregistrement d'un autre client : introuvable.
        $this->actingAs($stranger)->get($this->url())->assertNotFound();
        $this->actingAs($stranger)->get("/clients/{$this->client->id}/records/{$record->id}")->assertNotFound();
        $this->actingAs($stranger)->get("/clients/{$this->other->id}/records/{$record->id}")->assertNotFound();

        // Suppression définitive : droit de gestion et confirmation.
        $technician = $this->userFor($this->client, UserRole::Technician);
        $this->actingAs($technician)->delete("/clients/{$this->client->id}/records/{$record->id}", ['confirmation' => 'A'])->assertForbidden();
        $this->actingAs($this->manager)->delete("/clients/{$this->client->id}/records/{$record->id}", ['confirmation' => 'A'])->assertRedirect();
        $this->assertSame(0, DB::table('cx_contrats')->count());
    }

    public function test_inactive_or_default_fiches_have_no_client_pages(): void
    {
        $this->type->update(['is_active' => false]);
        $this->actingAs($this->manager)->get($this->url())->assertNotFound();

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/fiches/equipment")->assertNotFound();
        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/fiches/inconnue")->assertNotFound();
    }

    public function test_records_are_searchable_and_exportable(): void
    {
        $this->create(['nom' => 'Contrat Zephyr', 'postes' => 12, 'numero' => 'ZX-998'])->assertSessionHasNoErrors();

        $group = collect(app(GlobalSearch::class)->search($this->manager, 'ZX-998')['groups'])->firstWhere('type', 'records');
        $this->assertSame(1, $group['total']);
        $this->assertSame('Contrat Zephyr', $group['items'][0]['title']);

        $stranger = $this->userFor($this->other);
        $this->assertSame(0, collect(app(GlobalSearch::class)->search($stranger, 'Zephyr')['groups'])->sum('total'));

        $content = $this->actingAs($this->manager)->get($this->url('/export/csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Contrat Zephyr', $content);
        $this->assertStringContainsString('Postes couverts', $content);
        $this->assertDatabaseHas('audit_logs', ['event' => 'export.generated', 'client_id' => $this->client->id]);

        $reader = $this->userFor($this->client, UserRole::Reader);
        $this->actingAs($reader)->get($this->url('/export/csv'))->assertForbidden();
    }

    public function test_a_record_lists_the_records_pointing_to_it(): void
    {
        $interventions = $this->createRecordType('interventions', ['name' => 'Intervention', 'plural_name' => 'Interventions']);
        $this->addRecordField($interventions, 'contrat', 'reference', ['label' => 'Contrat', 'options' => ['target' => 'record:'.$this->type->id]]);
        $this->create(['nom' => 'CT-1', 'postes' => 1])->assertSessionHasNoErrors();
        $contract = CustomRecord::query()->sole();

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/fiches/interventions", ['fields' => ['nom' => 'Visite annuelle', 'contrat' => $contract->id]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/records/{$contract->id}")
            ->assertInertia(fn ($page) => $page->where('backlinks.0.title', 'Visite annuelle')->where('backlinks.0.field', 'Contrat'));

        // Un contrat d'un autre client ne peut pas être désigné.
        $foreign = new CustomRecord(['status' => 'active']);
        $foreign->forceFill(['record_type_id' => $this->type->id, 'title' => 'Autre']);
        $foreign->client()->associate($this->other);
        $foreign->save();

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/fiches/interventions", ['fields' => ['nom' => 'X', 'contrat' => $foreign->id]])
            ->assertSessionHasErrors('fields.contrat');
    }

    public function test_files_can_be_attached_to_a_record_of_the_same_client_only(): void
    {
        $this->create(['nom' => 'A', 'postes' => 1])->assertSessionHasNoErrors();
        $record = CustomRecord::query()->sole();
        $foreign = new CustomRecord(['status' => 'active']);
        $foreign->forceFill(['record_type_id' => $this->type->id, 'title' => 'Autre']);
        $foreign->client()->associate($this->other);
        $foreign->save();
        $pdf = fn () => UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/attachments", [
            'file' => $pdf(), 'attachable_type' => 'custom_record', 'attachable_id' => $foreign->id,
        ])->assertStatus(422);

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/attachments", [
            'file' => $pdf(), 'attachable_type' => 'custom_record', 'attachable_id' => $record->id,
        ])->assertSessionHasNoErrors();

        $attachment = Attachment::query()->sole();
        $this->assertSame(['custom_record', $record->id], [$attachment->attachable_type, $attachment->attachable_id]);
        $this->assertMatchesRegularExpression('#^attachments/\d+/[0-9a-f-]{36}$#', $attachment->stored_path);

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/records/{$record->id}")
            ->assertInertia(fn ($page) => $page->has('attachments.items', 1));
        $this->actingAs($this->manager)->get("/files/clients/{$this->client->id}")
            ->assertInertia(fn ($page) => $page->where('files.data.0.linked.type', 'custom_record'));
    }

    public function test_record_pages_render(): void
    {
        $this->create(['nom' => 'A', 'postes' => 1])->assertSessionHasNoErrors();
        $record = CustomRecord::query()->sole();

        foreach ([$this->url(), $this->url('/create'), $this->url().'?status=all&sort=postes&direction=desc&q=A', "/clients/{$this->client->id}/records/{$record->id}", "/clients/{$this->client->id}/records/{$record->id}/edit"] as $url) {
            $this->actingAs($this->manager)->get($url)->assertOk();
        }
    }
}
