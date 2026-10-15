<?php

namespace Tests\Feature\Internet;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\InternetLink;
use App\Models\Isp;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use Tests\TestCase;

/**
 * Liens d'accès à Internet d'un client (onglet FAI).
 */
class InternetLinkTest extends TestCase
{
    private Client $client;

    private Client $other;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->other = Client::factory()->create();
        $this->manager = $this->userFor($this->client, UserRole::Manager);
    }

    private function isp(string $slug = 'orange'): Isp
    {
        return Isp::query()->where('slug', $slug)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'isp_id' => $this->isp()->id,
            'name' => 'Fibre principale',
            'role' => 'primary',
            'status' => 'active',
            'technology' => 'ftto',
            'download_mbps' => 1000,
            'upload_mbps' => 1000,
            'line_reference' => 'FTTO-123456',
            'public_ips' => '203.0.113.8/29',
            'wan_gateway' => '203.0.113.9',
            ...$overrides,
        ];
    }

    private function link(Client $client, array $attributes = []): InternetLink
    {
        $link = new InternetLink(['name' => 'Lien', 'role' => 'primary', 'status' => 'active', 'operator_name' => 'Opérateur', ...$attributes]);
        $link->client()->associate($client);
        $link->save();

        return $link;
    }

    public function test_catalog_is_loaded_with_bundled_logos(): void
    {
        $this->assertGreaterThan(50, Isp::query()->count());
        $this->assertSame('Orange', $this->isp()->name);
        $this->assertNotNull($this->isp('bouygues-telecom')->bundledLogoPath());
    }

    public function test_link_is_created_with_relations_and_shown_with_operator(): void
    {
        $site = Site::factory()->for($this->client)->create();
        $router = Equipment::factory()->for($this->client)->create(['name' => 'FW-01']);
        $document = new Document(['title' => 'Procédure de bascule', 'status' => 'published']);
        $document->forceFill(['content_html' => '<p>x</p>', 'content_text' => 'x']);
        $document->client()->associate($this->client);
        $document->save();

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/internet-links", $this->payload([
            'site_id' => $site->id, 'equipment_ids' => [$router->id], 'document_ids' => [$document->id], 'tags' => ['Critique'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $link = InternetLink::query()->sole();
        $this->assertSame($this->isp()->id, $link->isp_id);
        $this->assertNull($link->operator_name);
        $this->assertSame(['FW-01'], $link->equipment()->pluck('name')->all());
        $this->assertSame(['Critique'], $link->tags()->pluck('name')->all());
        $this->assertSame(1, AuditLog::query()->where('event', 'internet_link.created')->count());

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/internet-links/{$link->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('InternetLinks/Show')
                ->where('link.operator', 'Orange')
                ->where('link.bandwidth_label', '1 Gbit/s ↓ / 1 Gbit/s ↑')
                ->where('link.isp.support_phone', '3900')
                ->where('link.logo_url', fn ($url) => str_contains((string) $url, "/isps/{$this->isp()->id}/logo"))
                ->has('link.equipment', 1)
                ->has('link.documents', 1));

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/internet-links")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('InternetLinks/Index')->has('links', 1)->where('context.counts.internet_links', 1));
    }

    public function test_operator_is_required_from_catalog_or_by_name(): void
    {
        $url = "/clients/{$this->client->id}/internet-links";

        $this->actingAs($this->manager)->post($url, $this->payload(['isp_id' => null]))->assertSessionHasErrors(['isp_id']);

        $this->actingAs($this->manager)->post($url, $this->payload(['isp_id' => null, 'operator_name' => 'Opérateur local']))->assertSessionHasNoErrors();
        $this->assertSame('Opérateur local', InternetLink::query()->sole()->operator_name);
    }

    public function test_free_operator_name_is_dropped_when_a_catalog_operator_is_chosen(): void
    {
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/internet-links", $this->payload(['operator_name' => 'Doublon']))->assertSessionHasNoErrors();

        $this->assertNull(InternetLink::query()->sole()->operator_name);
    }

    public function test_relations_must_belong_to_the_client(): void
    {
        $foreignSite = Site::factory()->for($this->other)->create();
        $foreignEquipment = Equipment::factory()->for($this->other)->create();

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/internet-links", $this->payload([
            'site_id' => $foreignSite->id, 'equipment_ids' => [$foreignEquipment->id],
        ]))->assertSessionHasErrors(['site_id', 'equipment_ids.0']);

        $this->assertSame(0, InternetLink::query()->count());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/internet-links", $this->payload([
            'technology' => 'pigeon', 'wan_gateway' => 'pas-une-ip', 'download_mbps' => -5, 'wan_vlan' => 5000,
        ]))->assertSessionHasErrors(['technology', 'wan_gateway', 'download_mbps', 'wan_vlan']);
    }

    public function test_links_of_unassigned_clients_are_not_found(): void
    {
        $foreign = $this->link($this->other);

        // Lien d'un autre client sous l'URL du client autorisé : liaison restreinte, 404.
        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/internet-links/{$foreign->id}")->assertNotFound();
        // Client non attribué : 404.
        $this->actingAs($this->manager)->get("/clients/{$this->other->id}/internet-links/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->manager)->get("/clients/{$this->other->id}/internet-links")->assertNotFound();
    }

    public function test_reader_can_view_but_not_modify(): void
    {
        $reader = $this->userFor($this->client, UserRole::Reader);
        $link = $this->link($this->client);

        $this->actingAs($reader)->get("/clients/{$this->client->id}/internet-links/{$link->id}")->assertOk();
        $this->actingAs($reader)->post("/clients/{$this->client->id}/internet-links", $this->payload())->assertForbidden();
        $this->actingAs($reader)->put("/clients/{$this->client->id}/internet-links/{$link->id}", $this->payload())->assertForbidden();
        $this->actingAs($reader)->post("/clients/{$this->client->id}/internet-links/{$link->id}/archive")->assertForbidden();
    }

    public function test_hidden_operator_is_refused_for_new_links_but_kept_on_existing_ones(): void
    {
        $isp = $this->isp('sosh');
        $link = $this->link($this->client, ['isp_id' => $isp->id, 'operator_name' => null]);
        $isp->update(['is_active' => false]);

        $this->actingAs($this->manager)->post("/clients/{$this->client->id}/internet-links", $this->payload(['isp_id' => $isp->id]))
            ->assertSessionHasErrors('isp_id');

        $this->actingAs($this->manager)->put("/clients/{$this->client->id}/internet-links/{$link->id}", $this->payload(['isp_id' => $isp->id, 'name' => 'Renommé']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Renommé', $link->fresh()->name);
    }

    public function test_update_is_audited_and_archive_and_deletion_follow_permissions(): void
    {
        $link = $this->link($this->client);
        $technician = $this->userFor($this->client, UserRole::Technician);

        $this->actingAs($technician)->put("/clients/{$this->client->id}/internet-links/{$link->id}", $this->payload(['download_mbps' => 500]))
            ->assertSessionHasNoErrors();

        $entry = AuditLog::query()->where('event', 'internet_link.updated')->latest('id')->firstOrFail();
        $this->assertSame('Orange', $entry->changes['isp_id']['new']);

        $this->actingAs($technician)->post("/clients/{$this->client->id}/internet-links/{$link->id}/archive")->assertRedirect();
        $this->assertSame('archived', $link->fresh()->status->value);

        // Suppression définitive : droit de gestion et confirmation par saisie du nom.
        $this->actingAs($technician)->delete("/clients/{$this->client->id}/internet-links/{$link->id}", ['confirmation' => 'Fibre principale'])->assertForbidden();
        $this->actingAs($this->manager)->delete("/clients/{$this->client->id}/internet-links/{$link->id}", ['confirmation' => 'faux'])->assertSessionHasErrors('confirmation');
        $this->actingAs($this->manager)->delete("/clients/{$this->client->id}/internet-links/{$link->id}", ['confirmation' => 'Fibre principale'])->assertRedirect();
        $this->assertSame(0, InternetLink::query()->count());
    }

    public function test_subnet_can_be_served_by_a_link_of_the_same_client_only(): void
    {
        $link = $this->link($this->client);
        $foreign = $this->link($this->other);
        $url = "/clients/{$this->client->id}/subnets";

        $this->actingAs($this->manager)->post($url, ['name' => 'WAN', 'cidr' => '203.0.113.8/29', 'purpose' => 'wan', 'internet_link_id' => $foreign->id])
            ->assertSessionHasErrors('internet_link_id');
        $this->actingAs($this->manager)->post($url, ['name' => 'WAN', 'cidr' => '203.0.113.8/29', 'purpose' => 'inconnu'])
            ->assertSessionHasErrors('purpose');

        $this->actingAs($this->manager)->post($url, ['name' => 'WAN', 'cidr' => '203.0.113.8/29', 'purpose' => 'wan', 'internet_link_id' => $link->id])
            ->assertSessionHasNoErrors();

        $subnet = Subnet::query()->sole();
        $this->assertSame($link->id, $subnet->internet_link_id);

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/network")
            ->assertInertia(fn ($page) => $page->where('subnets.0.purpose', 'wan')->where('subnets.0.internet_link.id', $link->id));
    }

    public function test_credentials_can_be_attached_to_a_link_of_the_same_client_only(): void
    {
        $link = $this->link($this->client);
        $foreign = $this->link($this->other);
        $url = "/clients/{$this->client->id}/credentials";

        $this->actingAs($this->manager)->post($url, ['label' => 'Espace client', 'access_type' => 'web', 'internet_link_id' => $foreign->id])
            ->assertSessionHasErrors('internet_link_id');

        $this->actingAs($this->manager)->post($url, ['label' => 'Espace client', 'access_type' => 'web', 'internet_link_id' => $link->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get("/clients/{$this->client->id}/internet-links/{$link->id}")
            ->assertInertia(fn ($page) => $page->has('credentials', 1)->where('credentials.0.internet_link.id', $link->id));
    }

    public function test_export_lists_links_with_their_operator(): void
    {
        $this->link($this->client, ['isp_id' => $this->isp()->id, 'operator_name' => null, 'name' => 'Fibre export']);
        $this->link($this->other, ['name' => 'Lien caché']);

        $content = $this->actingAs($this->manager)->get("/clients/{$this->client->id}/export/internet_links/csv")->assertOk()->streamedContent();

        $this->assertStringContainsString('Fibre export', $content);
        $this->assertStringContainsString('Orange', $content);
        $this->assertStringNotContainsString('Lien caché', $content);
    }
}
