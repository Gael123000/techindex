<?php

namespace Tests\Feature\Data;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Credential;
use App\Models\Equipment;
use App\Secrets\CredentialSecrets;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ExportTest extends TestCase
{
    public function test_csv_export_neutralizes_formulas(): void
    {
        $client = Client::factory()->create();
        Equipment::factory()->for($client)->create([
            'name' => '=HYPERLINK("http://evil.test","clic")',
            'description' => '+cmd|\'/C calc\'!A0',
            'notes' => '@SUM(1+1)',
        ]);

        $content = $this->actingAs($this->admin())->get("/clients/{$client->id}/export/equipment/csv")->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString("'+cmd", $content);
        $this->assertStringContainsString("'@SUM", $content);
        $this->assertStringNotContainsString(';=HYPERLINK', $content);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
    }

    public function test_xlsx_export_writes_text_cells_never_formulas(): void
    {
        $client = Client::factory()->create();
        Equipment::factory()->for($client)->create(['name' => '=1+1']);

        $content = $this->actingAs($this->admin())->get("/clients/{$client->id}/export/equipment/xlsx")->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $content);

        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $sheet);

        $reader = new Reader;
        $reader->open($path);
        $values = [];

        foreach ($reader->getSheetIterator() as $worksheet) {
            foreach ($worksheet->getRowIterator() as $row) {
                $values[] = $row->toArray();
            }
        }

        $reader->close();
        $this->assertSame("'=1+1", $values[1][1]);
    }

    public function test_exports_never_contain_secrets(): void
    {
        $client = Client::factory()->create();
        $equipment = Equipment::factory()->for($client)->create();
        $credential = new Credential(['label' => 'Accès admin', 'access_type' => 'web', 'username' => 'root', 'equipment_id' => $equipment->id]);
        $credential->client()->associate($client);
        app(CredentialSecrets::class)->apply($credential, 'Secret-Export-123');
        $credential->save();
        $admin = $this->admin();

        foreach (['equipment', 'services', 'documents', 'subnets'] as $dataset) {
            $content = $this->actingAs($admin)->get("/clients/{$client->id}/export/{$dataset}/csv")->assertOk()->streamedContent();
            $this->assertStringNotContainsString('Secret-Export-123', $content);
            $this->assertStringNotContainsString((string) $credential->fresh()->secret_ciphertext, $content);
        }

        $this->actingAs($admin)->get("/clients/{$client->id}/export/credentials/csv")->assertNotFound();
    }

    public function test_export_requires_the_export_permission(): void
    {
        $client = Client::factory()->create();
        $technician = $this->userFor($client, UserRole::Technician);
        $exporter = $this->userFor($client, UserRole::Technician, ['can_export' => true]);

        $this->actingAs($technician)->get("/clients/{$client->id}/export/equipment/csv")->assertForbidden();
        $this->actingAs($technician)->get('/exports/equipment/csv')->assertForbidden();
        $this->actingAs($exporter)->get("/clients/{$client->id}/export/equipment/csv")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'export.generated', 'user_id' => $exporter->id, 'client_id' => $client->id]);
    }

    public function test_global_export_only_includes_clients_with_export_permission(): void
    {
        $allowed = Client::factory()->create();
        $viewOnly = Client::factory()->create();
        Equipment::factory()->for($allowed)->create(['name' => 'EQ-AUTORISE']);
        Equipment::factory()->for($viewOnly)->create(['name' => 'EQ-LECTURE-SEULE']);
        Equipment::factory()->create(['name' => 'EQ-ETRANGER']);

        $user = $this->userFor($allowed, UserRole::Technician, ['can_export' => true]);
        $this->assign($user, $viewOnly);

        $content = $this->actingAs($user)->get('/exports/equipment/csv')->assertOk()->streamedContent();

        $this->assertStringContainsString('EQ-AUTORISE', $content);
        $this->assertStringNotContainsString('EQ-LECTURE-SEULE', $content);
        $this->assertStringNotContainsString('EQ-ETRANGER', $content);
    }
}
