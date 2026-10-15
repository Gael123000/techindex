<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Credential;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\InternetLink;
use App\Models\Isp;
use App\Models\User;
use App\Secrets\CredentialSecrets;
use App\Services\Search\GlobalSearch;
use Tests\CommittedDataTestCase;

class SearchTest extends CommittedDataTestCase
{
    private Client $mine;

    private Client $other;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Client::factory()->create(['name' => 'Client Visible']);
        $this->other = Client::factory()->create(['name' => 'Client Caché']);
        $this->user = $this->userFor($this->mine);

        $visible = Equipment::factory()->for($this->mine)->create(['name' => 'SRV-PARTAGE', 'serial_number' => 'ABC123XYZ', 'model' => 'PowerEdge R760']);
        $visible->interfaces()->create(['ip_address' => '192.168.50.10', 'mac_address' => 'AA:BB:CC:00:11:22', 'is_primary' => true]);

        $hidden = Equipment::factory()->for($this->other)->create(['name' => 'SRV-PARTAGE-SECRET', 'serial_number' => 'ABC123OTHER', 'model' => 'PowerEdge R760']);
        $hidden->interfaces()->create(['ip_address' => '192.168.50.20', 'is_primary' => true]);
    }

    private function search(array $query, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->get('/search?'.http_build_query($query))->assertOk();
    }

    private function results(string $term, array $filters = []): array
    {
        return app(GlobalSearch::class)->search($this->user, $term, $filters);
    }

    private function group(array $results, string $type): array
    {
        return collect($results['groups'])->firstWhere('type', $type);
    }

    public function test_equipment_is_found_by_name_serial_model_ip_and_mac(): void
    {
        foreach (['SRV-PARTAGE', 'ABC123', 'PowerEdge', '192.168.50', 'aa:bb:cc', 'AABBCC'] as $term) {
            $group = $this->group($this->results($term), 'equipment');
            $this->assertSame(1, $group['total'], "Recherche « {$term} »");
            $this->assertSame('SRV-PARTAGE', $group['items'][0]['title']);
        }
    }

    public function test_results_and_counts_never_include_inaccessible_clients(): void
    {
        $response = $this->search(['q' => 'PowerEdge']);
        $content = $response->getContent();

        $this->assertStringNotContainsString('SRV-PARTAGE-SECRET', $content);
        $this->assertStringNotContainsString('Client Caché', $content);
        $response->assertInertia(fn ($page) => $page->where('results.groups.2.type', 'equipment')->where('results.groups.2.total', 1));

        // Forcer le filtre sur un client inaccessible ne révèle rien.
        $group = $this->group($this->results('PowerEdge', ['client' => $this->other->id]), 'equipment');
        $this->assertSame(0, $group['total']);
    }

    public function test_admin_search_covers_all_clients(): void
    {
        $results = app(GlobalSearch::class)->search($this->admin(), 'PowerEdge');

        $this->assertSame(2, $this->group($results, 'equipment')['total']);
    }

    public function test_documents_are_searched_in_content_with_excerpt(): void
    {
        $this->actingAs($this->user)->post("/clients/{$this->mine->id}/documents", [
            'title' => 'Plan de reprise', 'status' => 'published',
            'content' => '<p>En cas de sinistre, la restauration Veeam démarre depuis le NAS externalisé.</p>',
        ]);

        $group = $this->group($this->results('restauration veeam'), 'documents');

        $this->assertSame(1, $group['total']);
        $this->assertStringContainsString('restauration Veeam', $group['items'][0]['excerpt']);
    }

    public function test_secrets_and_credentials_are_never_searchable(): void
    {
        $credential = new Credential(['label' => 'AccesUniqueLabel', 'access_type' => 'ssh', 'username' => 'rootunique']);
        $credential->client()->associate($this->mine);
        app(CredentialSecrets::class)->apply($credential, 'MotSecretIntrouvable');
        $credential->save();

        foreach (['MotSecretIntrouvable', 'AccesUniqueLabel', 'rootunique'] as $term) {
            $this->search(['q' => $term])->assertInertia(fn ($page) => $page->where(
                'results.groups',
                fn ($groups): bool => collect($groups)->sum('total') === 0,
            ));
            $this->assertSame(0, collect($this->results($term)['groups'])->sum('total'));
        }
    }

    public function test_tags_are_searchable(): void
    {
        $this->actingAs($this->user)->put("/clients/{$this->mine->id}/equipment/".Equipment::query()->where('name', 'SRV-PARTAGE')->value('id'), [
            'name' => 'SRV-PARTAGE', 'equipment_type_id' => Equipment::query()->where('name', 'SRV-PARTAGE')->value('equipment_type_id'),
            'status' => 'in_service', 'tags' => ['Critique-Prod'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->group($this->results('Critique-Prod'), 'equipment')['total']);
    }

    public function test_type_filter_paginates_a_single_type(): void
    {
        $this->search(['q' => 'SRV', 'type' => 'equipment'])
            ->assertInertia(fn ($page) => $page->where('results.mode', 'type')->where('results.results.total', 1));
    }

    public function test_short_queries_are_not_executed(): void
    {
        $this->search(['q' => 'a'])->assertInertia(fn ($page) => $page->where('results', null));
    }

    public function test_reader_sees_documents_of_assigned_clients_only(): void
    {
        $reader = $this->userFor($this->mine, UserRole::Reader);
        $doc = new Document(['title' => 'Fiche cachée Zeta', 'status' => 'published']);
        $doc->forceFill(['content_html' => '<p>zeta</p>', 'content_text' => 'zeta']);
        $doc->client()->associate($this->other);
        $doc->save();

        $this->assertSame(0, collect(app(GlobalSearch::class)->search($reader, 'Zeta')['groups'])->sum('total'));
    }

    public function test_internet_links_are_found_by_operator_line_reference_and_public_ip(): void
    {
        foreach ([[$this->mine, 'Fibre siège', 'NDI-998877'], [$this->other, 'Fibre cachée', 'NDI-998800']] as [$client, $name, $line]) {
            $link = new InternetLink([
                'isp_id' => Isp::query()->where('slug', 'orange')->value('id'), 'name' => $name, 'role' => 'primary', 'status' => 'active',
                'line_reference' => $line, 'public_ips' => '198.51.100.8/29',
            ]);
            $link->client()->associate($client);
            $link->save();
        }

        foreach (['Orange', 'NDI-9988', '198.51.100'] as $term) {
            $group = $this->group($this->results($term), 'internet_links');
            $this->assertSame(1, $group['total'], "Recherche « {$term} »");
            $this->assertSame('Fibre siège', $group['items'][0]['title']);
            $this->assertNotNull($group['items'][0]['logo_url']);
        }
    }

    public function test_instant_suggestions_respect_access_rights(): void
    {
        $response = $this->actingAs($this->user)->getJson('/search/suggest?q=PowerEdge')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('SRV-PARTAGE-SECRET', $response->getContent());
        $response->assertJsonPath('groups.0.type', 'equipment')
            ->assertJsonPath('groups.0.items.0.title', 'SRV-PARTAGE')
            ->assertJsonCount(1, 'groups.0.items');

        // Moins de deux caractères : aucune requête.
        $this->actingAs($this->user)->getJson('/search/suggest?q=P')->assertOk()->assertJsonPath('groups', []);
    }

    public function test_instant_suggestions_are_limited_per_type(): void
    {
        Equipment::factory()->count(7)->for($this->mine)->sequence(fn ($sequence) => ['name' => 'POSTE-LOT-'.$sequence->index])->create();

        $group = collect(app(GlobalSearch::class)->suggest($this->user, 'POSTE-LOT'))->firstWhere('type', 'equipment');

        $this->assertCount(5, $group['items']);
        $this->assertTrue($group['has_more']);
    }

    public function test_guests_get_no_suggestions(): void
    {
        $this->getJson('/search/suggest?q=PowerEdge')->assertUnauthorized();
    }
}
