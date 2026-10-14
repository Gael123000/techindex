<?php

namespace Tests\Feature\Files;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\FileFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Explorateur de fichiers : dossier racine par client, sous-dossiers,
 * import de dossiers, rangement des pièces jointes des fiches.
 */
class FileExplorerTest extends TestCase
{
    private Client $client;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->technician = $this->userFor($this->client, UserRole::Technician);
    }

    private function pdf(string $name = 'plan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upload(array $data = [], ?User $user = null, ?Client $client = null): TestResponse
    {
        return $this->actingAs($user ?? $this->technician)->postJson(route('files.clients.upload', $client ?? $this->client), [
            'file' => $this->pdf(),
            ...$data,
        ]);
    }

    private function folder(string $name, ?FileFolder $parent = null, ?Client $client = null): FileFolder
    {
        return FileFolder::query()->forceCreate([
            'client_id' => ($client ?? $this->client)->id,
            'parent_id' => $parent?->id,
            'name' => $name,
        ]);
    }

    public function test_each_accessible_client_is_a_root_folder(): void
    {
        $hidden = Client::factory()->create(['name' => 'Client non attribué']);

        $this->actingAs($this->technician)->get('/files')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Files/Index')
            ->has('clients', 1)
            ->where('clients.0.id', $this->client->id));

        $this->get(route('files.clients.show', $hidden))->assertNotFound();
        $this->get(route('files.clients.show', $this->client))->assertOk()->assertInertia(fn ($page) => $page->component('Files/Index')->where('client.id', $this->client->id));
    }

    public function test_a_file_is_uploaded_privately_into_a_folder(): void
    {
        $folder = $this->folder('Contrats');

        $this->upload(['folder_id' => $folder->id])->assertCreated();

        $attachment = Attachment::query()->sole();
        $this->assertSame($folder->id, $attachment->folder_id);
        $this->assertSame('client', $attachment->attachable_type);
        $this->assertSame($this->client->id, $attachment->attachable_id);
        $this->assertMatchesRegularExpression('#^attachments/\d+/[0-9a-f-]{36}$#', $attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->stored_path);

        $this->get(route('files.clients.show', ['client' => $this->client, 'folder' => $folder->id]))
            ->assertInertia(fn ($page) => $page->has('files.data', 1)->where('files.data.0.name', 'plan.pdf')->missing('files.data.0.stored_path'));
    }

    public function test_importing_a_folder_recreates_its_tree(): void
    {
        $target = $this->folder('Chantier');

        $this->upload(['folder_id' => $target->id, 'relative_path' => 'Projet/Plans/plan.pdf'])->assertCreated();
        $this->upload(['folder_id' => $target->id, 'relative_path' => 'projet/devis.pdf'])->assertCreated();

        $projet = FileFolder::query()->where('name', 'Projet')->sole();
        $plans = FileFolder::query()->where('name', 'Plans')->sole();
        $this->assertSame($target->id, $projet->parent_id);
        $this->assertSame($projet->id, $plans->parent_id);
        $this->assertSame(3, FileFolder::query()->count());
        $this->assertSame([$plans->id, $projet->id], Attachment::query()->orderBy('id')->pluck('folder_id')->all());
    }

    public function test_dangerous_paths_and_foreign_folders_are_rejected(): void
    {
        $other = Client::factory()->create();
        $this->assign($this->technician, $other);
        $foreign = $this->folder('Ailleurs', client: $other);

        foreach (['../../etc/plan.pdf', 'a/../plan.pdf', 'C:/plan.pdf', 'dossier*/plan.pdf'] as $path) {
            $this->upload(['relative_path' => $path])->assertStatus(422)->assertJsonValidationErrors('relative_path');
        }

        $this->upload(['folder_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('folder_id');
        $this->get(route('files.clients.show', ['client' => $this->client, 'folder' => $foreign->id]))->assertNotFound();
        $this->delete(route('files.clients.folders.destroy', [$this->client, $foreign]))->assertNotFound();

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame(1, FileFolder::query()->count());
    }

    public function test_content_must_match_the_extension(): void
    {
        $this->upload(['file' => UploadedFile::fake()->createWithContent('faux.pdf', '<?php system($_GET["c"]); ?>')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_an_equipment_attachment_appears_at_the_client_root_and_keeps_its_link_when_moved(): void
    {
        $equipment = Equipment::factory()->for($this->client)->create(['name' => 'SRV-01']);
        $this->actingAs($this->technician)->post("/clients/{$this->client->id}/attachments", [
            'file' => $this->pdf('garantie.pdf'),
            'attachable_type' => 'equipment',
            'attachable_id' => $equipment->id,
        ])->assertSessionHasNoErrors();
        $attachment = Attachment::query()->sole();

        $this->get(route('files.clients.show', $this->client))->assertInertia(fn ($page) => $page
            ->has('files.data', 1)
            ->where('files.data.0.linked.type', 'equipment')
            ->where('files.data.0.linked.label', 'SRV-01'));

        $folder = $this->folder('Garanties');
        $this->post(route('files.clients.move', $this->client), ['attachment_ids' => [$attachment->id], 'target_folder_id' => $folder->id])
            ->assertSessionHasNoErrors();

        $attachment->refresh();
        $this->assertSame($folder->id, $attachment->folder_id);
        $this->assertSame('equipment', $attachment->attachable_type);
        $this->get("/clients/{$this->client->id}/equipment/{$equipment->id}")->assertInertia(fn ($page) => $page->has('attachments.items', 1));
    }

    public function test_the_client_overview_lists_only_files_at_the_root(): void
    {
        $folder = $this->folder('Archives');
        $this->upload();
        $this->upload(['folder_id' => $folder->id]);

        $this->get(route('clients.show', $this->client))->assertInertia(fn ($page) => $page->has('attachments.items', 1));
    }

    public function test_deleting_a_folder_removes_its_own_files_and_restores_linked_ones(): void
    {
        $equipment = Equipment::factory()->for($this->client)->create();
        $folder = $this->folder('Ancien');
        $sub = $this->folder('Sous-dossier', $folder);

        $this->upload(['folder_id' => $sub->id])->assertCreated();
        $own = Attachment::query()->sole();
        $this->post("/clients/{$this->client->id}/attachments", ['file' => $this->pdf('lien.pdf'), 'attachable_type' => 'equipment', 'attachable_id' => $equipment->id]);
        $linked = Attachment::query()->where('attachable_type', 'equipment')->sole();
        $linked->forceFill(['folder_id' => $folder->id])->save();

        // Supprimer des fichiers déposés dans l'explorateur exige le droit de gestion.
        $this->delete(route('files.clients.folders.destroy', [$this->client, $folder]))->assertForbidden();

        $manager = $this->userFor($this->client, UserRole::Manager);
        $this->actingAs($manager)->delete(route('files.clients.folders.destroy', [$this->client, $folder]))->assertSessionHasNoErrors();

        $this->assertSame(0, FileFolder::query()->count());
        $this->assertNull(Attachment::query()->find($own->id));
        Storage::disk('local')->assertMissing($own->stored_path);
        $this->assertNull($linked->fresh()->folder_id);
        Storage::disk('local')->assertExists($linked->stored_path);
    }

    public function test_an_empty_folder_can_be_deleted_with_the_edit_permission(): void
    {
        $folder = $this->folder('Vide');

        $this->actingAs($this->technician)->delete(route('files.clients.folders.destroy', [$this->client, $folder]))->assertSessionHasNoErrors();
        $this->assertSame(0, FileFolder::query()->count());
    }

    public function test_folder_names_duplicates_and_cycles_are_controlled(): void
    {
        $store = fn (string $name, ?int $parent = null) => $this->actingAs($this->technician)
            ->post(route('files.clients.folders.store', $this->client), ['name' => $name, 'parent_id' => $parent]);

        $store('Plans')->assertSessionHasNoErrors();
        $store('PLANS')->assertSessionHasErrors('name');
        $store('..')->assertSessionHasErrors('name');
        $store('a\\b')->assertSessionHasErrors('name');

        $plans = FileFolder::query()->sole();
        $child = $this->folder('Étage 1', $plans);

        $this->post(route('files.clients.move', $this->client), ['folder_ids' => [$plans->id], 'target_folder_id' => $child->id])
            ->assertSessionHasErrors('parent_id');
        $this->assertNull($plans->fresh()->parent_id);
    }

    public function test_renaming_a_file_keeps_its_extension(): void
    {
        $this->upload()->assertCreated();
        $attachment = Attachment::query()->sole();

        $this->put(route('files.clients.attachments.update', [$this->client, $attachment]), ['name' => 'contrat signé.exe', 'description' => 'Version finale'])
            ->assertSessionHasNoErrors();

        $attachment->refresh();
        $this->assertSame('contrat signé.exe.pdf', $attachment->original_name);
        $this->assertSame('pdf', $attachment->extension);
        $this->assertSame('Version finale', $attachment->description);
    }

    public function test_readers_and_archived_clients_are_read_only(): void
    {
        $reader = $this->userFor($this->client, UserRole::Reader);
        $this->upload(user: $reader)->assertForbidden();
        $this->actingAs($reader)->post(route('files.clients.folders.store', $this->client), ['name' => 'X'])->assertForbidden();
        $this->get(route('files.clients.show', $this->client))->assertOk()->assertInertia(fn ($page) => $page->where('upload', null));

        $this->client->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        $this->upload()->assertForbidden();
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_global_search_only_returns_files_of_accessible_clients(): void
    {
        $this->upload(['file' => $this->pdf('rapport-audit.pdf')])->assertCreated();
        $other = Client::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('files.clients.upload', $other), ['file' => $this->pdf('rapport-audit.pdf')])->assertCreated();

        $this->actingAs($this->technician)->get('/files?q=rapport')->assertInertia(fn ($page) => $page->has('search.results', 1));
        $this->actingAs($admin)->get('/files?q=rapport')->assertInertia(fn ($page) => $page->has('search.results', 2));
    }
}
