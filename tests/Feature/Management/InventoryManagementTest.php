<?php

namespace Tests\Feature\Management;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    private Client $client;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->manager = $this->userFor($this->client, UserRole::Manager);
    }

    /**
     * Image PNG valide construite sans l'extension GD (absente des images
     * Docker et de l'environnement local, l'application n'en ayant pas besoin).
     */
    private function pngImage(string $name, int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat("\x2c\x6e\x9e", $width);

        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_client_can_be_created_with_logo_and_tags(): void
    {
        $admin = $this->admin();
        $logo = $this->pngImage('logo.png', 120, 60);
        $this->assertSame([120, 60], array_slice((array) getimagesize($logo->getPathname()), 0, 2));

        $this->actingAs($admin)->post('/clients', [
            'name' => 'Nouvelle Société', 'reference' => 'NS-001', 'email' => 'contact@ns.test',
            'tags' => ['PME', 'Infogérance'], 'logo' => $logo,
        ])->assertRedirect();

        $client = Client::query()->where('reference', 'NS-001')->sole();
        $this->assertSame(['Infogérance', 'PME'], $client->tags()->pluck('name')->all());
        $this->assertNotNull($client->logo_path);
        Storage::disk('local')->assertExists($client->logo_path);

        $this->actingAs($admin)->get("/clients/{$client->id}/logo")->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_svg_logo_is_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->admin())->post('/clients', ['name' => 'X', 'reference' => 'X-1', 'logo' => $svg])->assertSessionHasErrors('logo');
    }

    public function test_client_reference_must_be_unique(): void
    {
        $this->actingAs($this->admin())->post('/clients', ['name' => 'Doublon', 'reference' => $this->client->reference])
            ->assertSessionHasErrors('reference');
    }

    public function test_updates_are_audited_with_readable_differences(): void
    {
        $this->actingAs($this->manager)->put("/clients/{$this->client->id}", [
            'name' => 'Nom modifié', 'reference' => $this->client->reference, 'city' => 'Chambéry',
            'account_manager_id' => $this->manager->id,
        ])->assertRedirect();

        $log = AuditLog::query()->where('event', 'client.updated')->latest('id')->firstOrFail();
        $this->assertSame('Nom modifié', $log->changes['name']['new']);
        $this->assertSame('Chambéry', $log->changes['city']['new']);
        // Clé étrangère présentée par son libellé.
        $this->assertSame($this->manager->name, $log->changes['account_manager_id']['new']);
        $this->assertSame($this->manager->id, $log->user_id);
    }

    public function test_archive_and_restore_are_traced(): void
    {
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/archive")->assertRedirect();
        $this->assertTrue($this->client->fresh()->isArchived());
        $this->assertDatabaseHas('audit_logs', ['event' => 'client.archived', 'subject_id' => $this->client->id]);

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/restore")->assertRedirect();
        $this->assertFalse($this->client->fresh()->isArchived());
        $this->assertDatabaseHas('audit_logs', ['event' => 'client.restored', 'subject_id' => $this->client->id]);
    }

    public function test_equipment_with_multiple_addresses(): void
    {
        $type = EquipmentType::query()->where('slug', 'switch')->sole();
        $site = Site::factory()->for($this->client)->create();

        $payload = [
            'equipment_type_id' => $type->id, 'site_id' => $site->id, 'name' => 'SW-01', 'status' => 'in_service',
            'interfaces' => [
                ['label' => 'Mgmt', 'ip_address' => '10.1.1.2', 'mac_address' => '00-11-22-aa-bb-cc', 'is_primary' => true],
                ['label' => 'Uplink', 'ip_address' => '10.1.2.2', 'mac_address' => '', 'is_primary' => false],
                ['label' => '', 'ip_address' => '', 'mac_address' => ''],
            ],
        ];

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/equipment", $payload)->assertSessionHasNoErrors();

        $equipment = Equipment::query()->where('name', 'SW-01')->sole();
        $this->assertSame(2, $equipment->interfaces()->count());
        $this->assertSame('00:11:22:AA:BB:CC', $equipment->interfaces()->where('label', 'Mgmt')->value('mac_address'));

        // Modification des adresses : une seule entrée d'historique regroupe les changements.
        $payload['interfaces'] = [['label' => 'Mgmt', 'ip_address' => '10.1.1.3', 'is_primary' => true]];
        $this->actingAs($this->manager)->put("/clients/{$this->client->id}/equipment/{$equipment->id}", $payload)->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('event', 'equipment.updated')->sole();
        $this->assertStringContainsString('10.1.1.3', $log->changes['interfaces']['new']);
    }

    public function test_invalid_ip_and_mac_are_rejected(): void
    {
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/equipment", [
            'equipment_type_id' => EquipmentType::query()->value('id'), 'name' => 'X', 'status' => 'in_service',
            'interfaces' => [['ip_address' => '300.1.1.1', 'mac_address' => 'zz:zz']],
        ])->assertSessionHasErrors(['interfaces.0.ip_address', 'interfaces.0.mac_address']);
    }

    public function test_site_of_another_client_cannot_be_used(): void
    {
        $foreignSite = Site::factory()->create();

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/equipment", [
            'equipment_type_id' => EquipmentType::query()->value('id'), 'name' => 'X', 'status' => 'in_service', 'site_id' => $foreignSite->id,
        ])->assertSessionHasErrors('site_id');
    }

    public function test_equipment_links_stay_within_the_client(): void
    {
        $host = Equipment::factory()->for($this->client)->create();
        $vm = Equipment::factory()->for($this->client)->create();
        $foreign = Equipment::factory()->create();
        $url = "/clients/{$this->client->id}/equipment-links";

        $this->actingAs($this->manager)->post($url, ['source_equipment_id' => $host->id, 'target_equipment_id' => $foreign->id, 'type' => 'hosts'])
            ->assertSessionHasErrors('target_equipment_id');
        $this->actingAs($this->manager)->post($url, ['source_equipment_id' => $host->id, 'target_equipment_id' => $host->id, 'type' => 'hosts'])
            ->assertSessionHasErrors('target_equipment_id');
        $this->actingAs($this->manager)->post($url, ['source_equipment_id' => $host->id, 'target_equipment_id' => $vm->id, 'type' => 'hosts'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $host->outgoingLinks()->count());
        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/equipment/{$vm->id}")
            ->assertInertia(fn ($page) => $page->where('links.0.label', 'est hébergé par'));
    }

    public function test_service_details_are_validated_per_category(): void
    {
        $url = "/clients/{$this->client->id}/services";

        $this->actingAs($this->manager)->post($url, [
            'category' => 'email', 'name' => 'Messagerie', 'status' => 'active',
            'details' => ['mailbox_count' => 'beaucoup', 'platform' => 'Plateforme inconnue'],
        ])->assertSessionHasErrors(['details.mailbox_count', 'details.platform']);

        $this->actingAs($this->manager)->post($url, [
            'category' => 'email', 'name' => 'Messagerie', 'status' => 'active',
            'details' => ['mailbox_count' => '25', 'platform' => 'Google Workspace', 'champ_inconnu' => 'ignoré'],
        ])->assertSessionHasNoErrors();

        $service = Service::query()->sole();
        $this->assertSame(['platform' => 'Google Workspace', 'mailbox_count' => 25], $service->details);
    }

    public function test_licenses_and_certificates_crud(): void
    {
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/licenses", [
            'kind' => 'subscription', 'name' => 'Suite collaborative', 'status' => 'active', 'quantity' => 20,
            'starts_at' => '2026-01-01', 'expires_at' => '2025-12-31',
        ])->assertSessionHasErrors('expires_at');

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/licenses", [
            'kind' => 'subscription', 'name' => 'Suite collaborative', 'status' => 'active', 'quantity' => 20, 'expires_at' => '2027-01-31', 'auto_renewal' => true,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/certificates", [
            'name' => 'Certificat web', 'type' => 'wildcard', 'status' => 'active', 'common_name' => '*.exemple.test', 'expires_at' => '2027-03-01',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('licenses', ['client_id' => $this->client->id, 'name' => 'Suite collaborative', 'quantity' => 20]);
        $this->assertDatabaseHas('certificates', ['client_id' => $this->client->id, 'common_name' => '*.exemple.test']);
    }

    public function test_permanent_deletion_requires_manage_permission_and_confirmation(): void
    {
        $equipment = Equipment::factory()->for($this->client)->create(['name' => 'A-SUPPRIMER']);
        $technician = $this->userFor($this->client);
        $url = "/clients/{$this->client->id}/equipment/{$equipment->id}";

        $this->actingAs($technician)->delete($url, ['confirmation' => 'A-SUPPRIMER'])->assertForbidden();
        $this->actingAs($this->manager)->delete($url, ['confirmation' => 'autre'])->assertSessionHasErrors('confirmation');
        $this->actingAs($this->manager)->delete($url, ['confirmation' => 'A-SUPPRIMER'])->assertRedirect();

        $this->assertDatabaseMissing('equipment', ['id' => $equipment->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'equipment.deleted', 'subject_id' => $equipment->id]);
    }

    public function test_network_plan_validation(): void
    {
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/subnets", ['name' => 'LAN', 'cidr' => '192.168.1.0/33'])
            ->assertSessionHasErrors('cidr');
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/vlans", ['number' => 5000, 'name' => 'Trop grand'])
            ->assertSessionHasErrors('number');
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/vlans", ['number' => 10, 'name' => 'Users'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/vlans", ['number' => 10, 'name' => 'Doublon'])->assertSessionHasErrors('number');
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/subnets", ['name' => 'LAN', 'cidr' => '192.168.1.0/24', 'gateway' => '192.168.1.1'])
            ->assertSessionHasNoErrors();
    }
}
