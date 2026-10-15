<?php

namespace Tests\Feature\Internet;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Credential;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\InternetLink;
use App\Models\Isp;
use App\Models\Service;
use App\Services\Internet\LegacyInternetServiceConverter;
use App\Services\Tags\TagResolver;
use Tests\TestCase;

/**
 * Conversion des anciens services « Connexion Internet » en liens FAI.
 */
class LegacyInternetServiceTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function legacyService(string $name, string $provider, array $details = [], array $attributes = []): Service
    {
        $service = new Service([
            'category' => 'internet', 'name' => $name, 'provider' => $provider, 'reference' => 'CT-42', 'status' => 'active',
            'url' => 'https://portail.example', 'start_date' => '2024-01-15', 'end_date' => '2027-06-30',
            'description' => 'Lien du siège', 'notes' => 'Notes existantes', 'details' => $details, ...$attributes,
        ]);
        $service->client()->associate($this->client);
        $service->save();

        return $service;
    }

    public function test_internet_services_become_internet_links_without_losing_anything(): void
    {
        $service = $this->legacyService('Fibre siège', 'Orange Business Services', [
            'technology' => 'Fibre dédiée (FTTO)', 'bandwidth_down' => '1 Gbit/s', 'bandwidth_up' => '500 Mb/s symétrique ?',
            'public_ips' => '203.0.113.8/29', 'line_number' => 'NDI 0400000000', 'sla' => 'GTR 4 h', 'support_phone' => '0800 000 000',
        ]);
        $equipment = Equipment::factory()->for($this->client)->create();
        $service->equipment()->sync([$equipment->id]);
        $service->tags()->sync(app(TagResolver::class)->idsFor(['Critique']));

        $document = new Document(['title' => 'Bascule', 'status' => 'published']);
        $document->forceFill(['content_html' => '<p>x</p>', 'content_text' => 'x']);
        $document->client()->associate($this->client);
        $document->save();
        $document->services()->sync([$service->id]);

        $credential = new Credential(['label' => 'Espace client', 'access_type' => 'web', 'service_id' => $service->id]);
        $credential->client()->associate($this->client);
        $credential->save();

        $attachment = new Attachment;
        $attachment->forceFill([
            'client_id' => $this->client->id, 'attachable_type' => 'service', 'attachable_id' => $service->id,
            'original_name' => 'contrat.pdf', 'stored_path' => 'attachments/'.$this->client->id.'/00000000-0000-0000-0000-000000000000',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 10, 'checksum_sha256' => str_repeat('a', 64),
        ])->save();

        $result = app(LegacyInternetServiceConverter::class)->convertAll();

        $this->assertSame(['converted' => 1, 'kept' => 0], $result);
        $this->assertNull(Service::query()->find($service->id));

        $link = InternetLink::query()->sole();
        $this->assertSame(Isp::query()->where('slug', 'orange-business')->value('id'), $link->isp_id);
        $this->assertNull($link->operator_name);
        $this->assertSame('Fibre siège', $link->name);
        $this->assertSame('ftto', $link->technology->value);
        $this->assertSame(1000, $link->download_mbps);
        $this->assertNull($link->upload_mbps);
        $this->assertSame('203.0.113.8/29', $link->public_ips);
        $this->assertSame('NDI 0400000000', $link->line_reference);
        $this->assertSame('GTR 4 h', $link->gtr);
        $this->assertSame('0800 000 000', $link->support_phone);
        $this->assertSame('CT-42', $link->contract_reference);
        $this->assertSame('2024-01-15', $link->commissioned_at->format('Y-m-d'));
        $this->assertSame('2027-06-30', $link->commitment_ends_at->format('Y-m-d'));
        // Valeurs sans colonne dédiée : conservées dans les notes.
        $this->assertStringContainsString('Notes existantes', $link->notes);
        $this->assertStringContainsString('Débit montant : 500 Mb/s symétrique ?', $link->notes);
        $this->assertStringContainsString('Adresse web : https://portail.example', $link->notes);

        $this->assertSame([$equipment->id], $link->equipment()->pluck('equipment.id')->all());
        $this->assertSame([$document->id], $link->documents()->pluck('documents.id')->all());
        $this->assertSame(['Critique'], $link->tags()->pluck('name')->all());
        $this->assertSame($link->id, $credential->fresh()->internet_link_id);
        $this->assertNull($credential->fresh()->service_id);
        $this->assertSame(['internet_link', $link->id], [$attachment->fresh()->attachable_type, $attachment->fresh()->attachable_id]);
        $this->assertSame(1, AuditLog::query()->where('event', 'internet_link.converted')->where('subject_id', $link->id)->count());
    }

    public function test_unknown_operator_is_kept_as_free_text_and_status_is_mapped(): void
    {
        $this->legacyService('Ligne secours', 'Opérateur Inconnu', [], ['status' => 'inactive']);

        app(LegacyInternetServiceConverter::class)->convertAll();

        $link = InternetLink::query()->sole();
        $this->assertNull($link->isp_id);
        $this->assertSame('Opérateur Inconnu', $link->operator_name);
        $this->assertSame('terminated', $link->status->value);
    }

    public function test_a_service_with_a_certificate_is_kept_as_a_service(): void
    {
        $service = $this->legacyService('Lien avec certificat', 'SFR');
        $certificate = new Certificate(['name' => 'Cert', 'type' => 'public', 'status' => 'active', 'service_id' => $service->id]);
        $certificate->client()->associate($this->client);
        $certificate->save();

        $this->assertSame(['converted' => 0, 'kept' => 1], app(LegacyInternetServiceConverter::class)->convertAll());
        $this->assertNotNull($service->fresh());
        $this->assertSame(0, InternetLink::query()->count());
    }

    public function test_new_services_cannot_use_the_internet_category_but_legacy_ones_stay_editable(): void
    {
        $manager = $this->userFor($this->client, UserRole::Manager);
        $url = "/clients/{$this->client->id}/services";

        $this->actingAs($manager)->post($url, ['category' => 'internet', 'name' => 'Fibre', 'status' => 'active'])
            ->assertSessionHasErrors('category');

        $this->actingAs($manager)->get("{$url}/create")
            ->assertInertia(fn ($page) => $page->where('categories', fn ($categories) => collect($categories)->doesntContain('value', 'internet')));

        $legacy = $this->legacyService('Ancien lien', 'SFR');
        $this->actingAs($manager)->put("{$url}/{$legacy->id}", ['category' => 'internet', 'name' => 'Ancien lien renommé', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Ancien lien renommé', $legacy->fresh()->name);
    }
}
