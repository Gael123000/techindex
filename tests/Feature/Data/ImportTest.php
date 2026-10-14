<?php

namespace Tests\Feature\Data;

use App\Enums\ImportStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportTest extends TestCase
{
    private function csv(array $rows, string $name = 'clients.csv'): UploadedFile
    {
        $content = implode("\n", array_map(fn (array $row): string => implode(';', $row), $rows))."\n";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(User $user, UploadedFile $file, string $type = 'clients', string $mode = 'create', ?int $clientId = null): Import
    {
        $this->actingAs($user)->post('/imports', array_filter([
            'type' => $type,
            'mode' => $mode,
            'client_id' => $clientId,
            'file' => $file,
        ]))->assertSessionHasNoErrors();

        return Import::query()->latest('id')->firstOrFail();
    }

    public function test_templates_can_be_downloaded(): void
    {
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get('/imports/templates/clients/csv')->assertOk();
        $this->assertStringContainsString('Référence', $csv->streamedContent());

        $xlsx = $this->actingAs($admin)->get('/imports/templates/equipment/xlsx')->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
    }

    public function test_columns_are_mapped_and_rows_validated_before_import(): void
    {
        $admin = $this->admin();
        Client::factory()->create(['reference' => 'CLI-EXIST', 'name' => 'Existant']);

        $import = $this->upload($admin, $this->csv([
            ['Nom', 'Référence', 'E-mail', 'Colonne inconnue'],
            ['Nouveau client', 'CLI-NEW', 'contact@nouveau.test', 'x'],
            ['', 'CLI-SANS-NOM', '', ''],
            ['Mauvais mail', 'CLI-MAIL', 'pas-un-email', ''],
            ['Doublon', 'CLI-NEW', '', ''],
            ['Existant renommé', 'cli-exist', '', ''],
        ]));

        $this->assertSame(['name', 'reference', 'email', null], $import->mapping);

        $this->actingAs($admin)->get("/imports/{$import->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Imports/Show')
                ->where('analysis.summary.total', 5)
                ->where('analysis.summary.create', 1)
                ->where('analysis.summary.skip', 1)
                ->where('analysis.summary.error', 3)
                ->where('analysis.rows.0.action', 'create')
                ->where('analysis.rows.1.action', 'error')
                ->where('analysis.rows.3.errors.0', 'Doublon de la ligne 2 dans le fichier.')
                ->where('analysis.rows.4.action', 'skip'));

        // Des lignes sont en erreur : l'exécution est refusée sans choix explicite.
        $this->actingAs($admin)->post("/imports/{$import->id}/execute")->assertSessionHas('error');
        $this->assertSame(ImportStatus::Uploaded, $import->fresh()->status);
        $this->assertDatabaseMissing('clients', ['reference' => 'CLI-NEW']);
    }

    public function test_import_runs_once_errors_are_explicitly_skipped(): void
    {
        $admin = $this->admin();
        $import = $this->upload($admin, $this->csv([
            ['Nom', 'Référence'],
            ['Client A', 'CLI-A'],
            ['', 'CLI-B'],
        ]));

        $this->actingAs($admin)->put("/imports/{$import->id}/mapping", [
            'mapping' => ['name', 'reference'],
            'mode' => 'create',
            'skip_invalid_rows' => true,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post("/imports/{$import->id}/execute")->assertRedirect();

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->created_count);
        $this->assertSame(1, $import->error_count);
        $this->assertDatabaseHas('clients', ['reference' => 'CLI-A']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.completed', 'subject_id' => $import->id]);
    }

    public function test_full_report_lists_every_row_and_is_private_to_its_author(): void
    {
        $admin = $this->admin();
        $import = $this->upload($admin, $this->csv([
            ['Nom', 'Référence'],
            ['Client A', 'CLI-A'],
            ['', 'CLI-B'],
            ['=HYPERLINK("http://example.test")', 'CLI-C'],
        ]));

        $csv = $this->actingAs($admin)->get("/imports/{$import->id}/report/csv")->assertOk()->streamedContent();

        $this->assertStringContainsString('Ligne;Élément;Action;Erreurs;Remarques', $csv);
        $this->assertStringContainsString('2;"Client A (CLI-A)";Création', $csv);
        $this->assertMatchesRegularExpression('/^3;.*;Erreur;.*Nom/m', $csv);
        // Le libellé issu du fichier est neutralisé (injection de formule).
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        // Après exécution, le rapport enregistré reste téléchargeable.
        $this->actingAs($admin)->put("/imports/{$import->id}/mapping", ['mapping' => ['name', 'reference'], 'mode' => 'create', 'skip_invalid_rows' => true]);
        $this->actingAs($admin)->post("/imports/{$import->id}/execute");
        $this->assertSame(ImportStatus::Completed, $import->fresh()->status);
        $this->assertStringStartsWith('PK', $this->actingAs($admin)->get("/imports/{$import->id}/report/xlsx")->assertOk()->streamedContent());

        $other = User::factory()->role(UserRole::Manager)->create();
        $this->actingAs($other)->get("/imports/{$import->id}/report/csv")->assertForbidden();
    }

    public function test_update_mode_updates_existing_and_ignores_unknown(): void
    {
        $admin = $this->admin();
        $existing = Client::factory()->create(['reference' => 'CLI-1', 'name' => 'Ancien nom', 'city' => 'Lyon']);

        $import = $this->upload($admin, $this->csv([
            ['Nom', 'Référence', 'Ville'],
            ['Nouveau nom', 'CLI-1', ''],
            ['Inconnu', 'CLI-2', 'Paris'],
        ]), mode: 'update');

        $this->actingAs($admin)->post("/imports/{$import->id}/execute")->assertRedirect();

        $existing->refresh();
        $this->assertSame('Nouveau nom', $existing->name);
        // Une cellule vide n'efface pas la valeur existante.
        $this->assertSame('Lyon', $existing->city);
        $this->assertDatabaseMissing('clients', ['reference' => 'CLI-2']);
        $this->assertSame(1, $import->fresh()->updated_count);
        $this->assertSame(1, $import->fresh()->skipped_count);
    }

    public function test_equipment_import_from_excel_with_addresses(): void
    {
        $client = Client::factory()->create();
        $manager = $this->userFor($client, UserRole::Manager);
        Equipment::factory()->for($client)->create(['serial_number' => 'SN-EXIST', 'name' => 'Ancien']);

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Nom', 'Type', 'Numéro de série', 'Adresses IP', 'Adresses MAC', 'Fin de garantie', 'Statut']));
        $writer->addRow(Row::fromValues(['SRV-NEW', 'Serveur virtuel', 'SN-NEW', '10.0.0.10, 10.0.0.11', '00-11-22-33-44-55', '31/12/2027', 'En service']));
        $writer->addRow(Row::fromValues(['Renommé', 'Switch', 'sn-exist', '', '', '', '']));
        $writer->addRow(Row::fromValues(['Erreur', 'Type inexistant', 'SN-ERR', '999.1.1.1', '', '2027-02-30', 'Statut inconnu']));
        $writer->close();

        $import = $this->upload($manager, new UploadedFile($path, 'inventaire.xlsx', null, null, true), 'equipment', 'upsert', $client->id);

        $this->actingAs($manager)->get("/imports/{$import->id}")
            ->assertInertia(fn ($page) => $page
                ->where('analysis.summary.create', 1)
                ->where('analysis.summary.update', 1)
                ->where('analysis.summary.error', 1));

        $this->actingAs($manager)->put("/imports/{$import->id}/mapping", [
            'mapping' => $import->mapping, 'mode' => 'upsert', 'skip_invalid_rows' => true,
        ]);
        $this->actingAs($manager)->post("/imports/{$import->id}/execute");

        $new = Equipment::query()->where('serial_number', 'SN-NEW')->sole();
        $this->assertSame($client->id, $new->client_id);
        $this->assertSame('2027-12-31', $new->warranty_expires_at->format('Y-m-d'));
        $this->assertSame(['10.0.0.10', '10.0.0.11'], $new->interfaces()->pluck('ip_address')->all());
        $this->assertSame('00:11:22:33:44:55', $new->interfaces()->first()->mac_address);
        $this->assertSame('Renommé', Equipment::query()->where('serial_number', 'SN-EXIST')->value('name'));
        $this->assertDatabaseMissing('equipment', ['serial_number' => 'SN-ERR']);
    }

    public function test_equipment_import_requires_edit_permission_on_target_client(): void
    {
        $client = Client::factory()->create();
        $reader = $this->userFor($client, UserRole::Reader);

        $this->actingAs($reader)->post('/imports', [
            'type' => 'equipment', 'mode' => 'create', 'client_id' => $client->id,
            'file' => $this->csv([['Nom', 'Type'], ['X', 'Switch']]),
        ])->assertForbidden();
    }

    public function test_technician_cannot_import_clients(): void
    {
        $client = Client::factory()->create();
        $technician = $this->userFor($client);

        $this->actingAs($technician)->post('/imports', [
            'type' => 'clients', 'mode' => 'create', 'file' => $this->csv([['Nom', 'Référence'], ['X', 'X-1']]),
        ])->assertForbidden();
    }

    public function test_imports_are_private_to_their_author(): void
    {
        $admin = $this->admin();
        $import = $this->upload($admin, $this->csv([['Nom', 'Référence'], ['A', 'A-1']]));
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get("/imports/{$import->id}")->assertForbidden();
        $this->actingAs($manager)->post("/imports/{$import->id}/execute")->assertForbidden();
    }

    public function test_invalid_files_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/imports', [
            'type' => 'clients', 'mode' => 'create', 'file' => UploadedFile::fake()->createWithContent('clients.exe', 'MZ'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($admin)->post('/imports', [
            'type' => 'clients', 'mode' => 'create', 'file' => UploadedFile::fake()->createWithContent('vide.csv', "\n\n"),
        ])->assertSessionHasErrors('file');
    }

    public function test_windows_1252_csv_with_comma_separator_is_read(): void
    {
        $admin = $this->admin();
        $content = mb_convert_encoding("Nom,Référence,Ville\nCafé Démo,CLI-W,Évian\n", 'Windows-1252', 'UTF-8');

        $import = $this->upload($admin, UploadedFile::fake()->createWithContent('excel.csv', $content));
        $this->actingAs($admin)->post("/imports/{$import->id}/execute");

        $this->assertDatabaseHas('clients', ['reference' => 'CLI-W', 'name' => 'Café Démo', 'city' => 'Évian']);
    }
}
