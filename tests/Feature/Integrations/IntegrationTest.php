<?php

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationFrequency;
use App\Enums\LicenseStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Integration;
use App\Models\IntegrationAccount;
use App\Models\IntegrationRun;
use App\Models\License;
use App\Secrets\Contracts\SecretVault;
use App\Secrets\CredentialSecrets;
use App\Secrets\IntegrationSecrets;
use App\Secrets\Keyring;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    private const PAX8_SECRET = 'pax8-secret-ne-doit-jamais-sortir';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function configurePax8(): Integration
    {
        $this->actingAs($this->admin())->put('/admin/integrations/pax8', [
            'is_enabled' => true,
            'frequency' => 'daily',
            'credentials' => ['client_id' => 'pax8-client', 'client_secret' => self::PAX8_SECRET],
        ])->assertSessionHasNoErrors();

        return Integration::query()->where('provider', 'pax8')->sole();
    }

    /** @var list<array<string, mixed>> */
    private array $pax8Subscriptions = [];

    private bool $pax8Faked = false;

    /**
     * Réponses simulées de Pax8 ; les abonnements renvoyés suivent $pax8Subscriptions.
     *
     * @param  list<array<string, mixed>>  $subscriptions
     */
    private function fakePax8(array $subscriptions): void
    {
        $this->pax8Subscriptions = $subscriptions;

        if ($this->pax8Faked) {
            return;
        }

        $this->pax8Faked = true;

        Http::fake([
            'api.pax8.com/v1/token' => Http::response(['access_token' => 'jeton-pax8', 'expires_in' => 86400]),
            'api.pax8.com/v1/companies*' => Http::response([
                'content' => [
                    ['id' => 'co-1', 'name' => 'Exemple Industries SAS', 'website' => 'https://www.exemple.test', 'status' => 'Active', 'address' => ['city' => 'Lyon']],
                    ['id' => 'co-2', 'name' => 'Société Inconnue', 'status' => 'Active'],
                ],
                'page' => ['size' => 200, 'totalElements' => 2, 'totalPages' => 1, 'number' => 0],
            ]),
            'api.pax8.com/v1/subscriptions*' => fn () => Http::response(['content' => $this->pax8Subscriptions, 'page' => ['totalPages' => 1, 'number' => 0]]),
            'api.pax8.com/v1/products/*' => Http::response(['name' => 'Microsoft 365 Business Standard', 'vendorName' => 'Microsoft']),
        ]);
    }

    public function test_integrations_are_reserved_to_administrators(): void
    {
        $client = Client::factory()->create();
        $manager = $this->userFor($client, UserRole::Manager);

        $this->actingAs($manager)->get('/admin/integrations')->assertForbidden();
        $this->actingAs($manager)->get('/admin/integrations/pax8')->assertForbidden();
        $this->actingAs($manager)->put('/admin/integrations/pax8', ['frequency' => 'daily'])->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/integrations/inconnu')->assertNotFound();

        $this->actingAs($this->admin())->get('/admin/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Integrations/Index')->has('providers', 13));
    }

    public function test_credentials_are_encrypted_never_shown_and_kept_when_left_blank(): void
    {
        $admin = $this->admin();

        // Première configuration : les identifiants obligatoires sont exigés.
        $this->actingAs($admin)->put('/admin/integrations/pax8', ['is_enabled' => true, 'frequency' => 'daily'])
            ->assertSessionHasErrors(['credentials.client_id', 'credentials.client_secret']);

        $integration = $this->configurePax8();

        $this->assertNotNull($integration->credentials_ciphertext);
        $this->assertStringNotContainsString(self::PAX8_SECRET, (string) $integration->credentials_ciphertext);
        $this->assertSame(self::PAX8_SECRET, app(IntegrationSecrets::class)->read($integration)['client_secret']);

        $page = $this->actingAs($admin)->get('/admin/integrations/pax8')
            ->assertInertia(fn ($page) => $page
                ->where('integration.configured_credentials', ['client_id', 'client_secret'])
                ->where('integration.is_enabled', true));
        $this->assertStringNotContainsString(self::PAX8_SECRET, $page->getContent());
        $this->assertStringNotContainsString('pax8-client', $page->getContent());

        // Champ vide : valeur conservée ; aucune valeur dans l'historique.
        $this->actingAs($admin)->put('/admin/integrations/pax8', [
            'is_enabled' => true, 'frequency' => 'every_6_hours', 'credentials' => ['client_id' => '', 'client_secret' => ''],
        ])->assertSessionHasNoErrors();

        $integration->refresh();
        $this->assertSame(IntegrationFrequency::Every6Hours, $integration->frequency);
        $this->assertSame(self::PAX8_SECRET, app(IntegrationSecrets::class)->read($integration)['client_secret']);

        $audit = AuditLog::query()->where('subject_type', 'integration')->get()->toJson();
        $this->assertStringNotContainsString(self::PAX8_SECRET, $audit);
        $this->assertStringNotContainsString('pax8-client', $audit);
        $this->assertStringContainsString('client_secret', $audit);
    }

    public function test_api_address_must_belong_to_the_provider(): void
    {
        $this->actingAs($this->admin())->put('/admin/integrations/arrowsphere', [
            'is_enabled' => true, 'frequency' => 'daily',
            'settings' => ['base_url' => 'https://169.254.169.254/latest'],
            'credentials' => ['api_key' => 'cle'],
        ])->assertSessionHasErrors('settings.base_url');

        $this->actingAs($this->admin())->put('/admin/integrations/arrowsphere', [
            'is_enabled' => true, 'frequency' => 'daily',
            'settings' => ['base_url' => 'http://xsp.arrow.com/index.php/api'],
            'credentials' => ['api_key' => 'cle'],
        ])->assertSessionHasErrors('settings.base_url');

        $this->actingAs($this->admin())->put('/admin/integrations/arrowsphere', [
            'is_enabled' => true, 'frequency' => 'daily',
            'settings' => ['base_url' => 'https://xsp.arrow.com/index.php/api'],
            'credentials' => ['api_key' => 'cle'],
        ])->assertSessionHasNoErrors();
    }

    public function test_sync_imports_licences_for_linked_accounts_and_cancels_missing_ones(): void
    {
        $client = Client::factory()->create(['name' => 'Exemple Industries', 'website' => 'https://exemple.test']);
        $manual = License::query()->forceCreate(['client_id' => $client->id, 'kind' => 'license', 'name' => 'Licence manuelle', 'status' => 'active']);
        $integration = $this->configurePax8();
        $admin = $this->admin();

        $subscription = [
            'id' => 'sub-1', 'companyId' => 'co-1', 'productId' => 'prod-1', 'quantity' => 12, 'status' => 'Active',
            'billingTerm' => 'Annual', 'startDate' => '2026-01-01T00:00:00Z', 'commitmentTerm' => ['endDate' => '2027-01-01T00:00:00Z'],
        ];
        $this->fakePax8([$subscription]);

        // Première synchronisation : comptes découverts, rien n'est rattaché.
        $this->actingAs($admin)->post('/admin/integrations/pax8/sync')->assertSessionHas('success');
        $this->assertSame(2, $integration->accounts()->count());
        $this->assertSame(0, License::query()->whereNotNull('integration_account_id')->count());

        // Rapprochement automatique : même nom sans forme juridique.
        $this->actingAs($admin)->post('/admin/integrations/pax8/accounts/match')->assertSessionHas('success');
        $account = IntegrationAccount::query()->where('external_id', 'co-1')->sole();
        $this->assertSame($client->id, $account->client_id);
        $this->assertNull(IntegrationAccount::query()->where('external_id', 'co-2')->value('client_id'));

        $this->actingAs($admin)->post('/admin/integrations/pax8/sync')->assertSessionHas('success');

        $license = License::query()->where('external_id', 'sub-1')->sole();
        $this->assertSame($client->id, $license->client_id);
        $this->assertSame('Microsoft 365 Business Standard', $license->name);
        $this->assertSame('Microsoft', $license->publisher);
        $this->assertSame('Pax8', $license->supplier);
        $this->assertSame(12, $license->quantity);
        $this->assertSame('2027-01-01', $license->expires_at->toDateString());
        $this->assertSame('yearly', $license->billing_period->value);
        $this->assertSame(LicenseStatus::Active, $license->status);

        $this->assertDatabaseHas('audit_logs', ['event' => 'integration.licenses_synced', 'client_id' => $client->id]);
        $run = IntegrationRun::query()->latest('id')->first();
        $this->assertSame('success', $run->status->value);
        $this->assertSame(1, $run->stats['created']);

        // L'abonnement disparaît chez le fournisseur : licence résiliée, la licence manuelle n'est pas touchée.
        $this->fakePax8([]);
        $this->actingAs($admin)->post('/admin/integrations/pax8/sync');

        $this->assertSame(LicenseStatus::Cancelled, $license->fresh()->status);
        $this->assertSame(LicenseStatus::Active, $manual->fresh()->status);
        $this->assertNull($manual->fresh()->integration_account_id);

        // La fiche de la licence indique son origine.
        $this->actingAs($admin)->get("/clients/{$client->id}/licenses/{$license->id}")
            ->assertInertia(fn ($page) => $page->where('source.label', 'Pax8')->where('source.account', 'Exemple Industries SAS'));

        $this->assertStringNotContainsString(self::PAX8_SECRET, AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString(self::PAX8_SECRET, IntegrationRun::query()->get()->toJson());
    }

    public function test_relinking_an_account_moves_its_licences_and_unlinking_detaches_them(): void
    {
        $first = Client::factory()->create();
        $second = Client::factory()->create();
        $integration = $this->configurePax8();
        $account = $integration->accounts()->create(['external_id' => 'co-1', 'name' => 'Exemple', 'client_id' => $first->id]);
        $license = License::query()->forceCreate([
            'client_id' => $first->id, 'kind' => 'subscription', 'name' => 'M365', 'status' => 'active',
            'integration_account_id' => $account->id, 'external_id' => 'sub-1',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/integrations/pax8/accounts/{$account->id}", ['client_id' => $second->id])->assertSessionHasNoErrors();
        $this->assertSame($second->id, $license->fresh()->client_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'integration.account_linked', 'client_id' => $second->id]);

        $this->actingAs($admin)->put("/admin/integrations/pax8/accounts/{$account->id}", ['client_id' => null])->assertSessionHasNoErrors();
        $license->refresh();
        $this->assertSame($second->id, $license->client_id);
        $this->assertNull($license->integration_account_id);

        // Un compte d'une autre intégration n'est pas accessible par cette adresse.
        $this->actingAs($admin)->put("/admin/integrations/sophos/accounts/{$account->id}", ['client_id' => $first->id])->assertNotFound();
    }

    public function test_deleting_an_integration_keeps_imported_licences(): void
    {
        $client = Client::factory()->create();
        $integration = $this->configurePax8();
        $account = $integration->accounts()->create(['external_id' => 'co-1', 'name' => 'Exemple', 'client_id' => $client->id]);
        $license = License::query()->forceCreate([
            'client_id' => $client->id, 'kind' => 'subscription', 'name' => 'M365', 'status' => 'active',
            'integration_account_id' => $account->id, 'external_id' => 'sub-1',
        ]);

        $this->actingAs($this->admin())->delete('/admin/integrations/pax8')->assertRedirect('/admin/integrations');

        $this->assertModelMissing($integration);
        $this->assertModelExists($license);
        $this->assertNull($license->fresh()->integration_account_id);
    }

    public function test_connection_errors_are_reported_without_details(): void
    {
        $this->configurePax8();
        Http::fake(['api.pax8.com/v1/token' => Http::response(['error' => 'access_denied', 'error_description' => 'Unauthorized client pax8-client'], 401)]);

        $this->actingAs($this->admin())->post('/admin/integrations/pax8/test')
            ->assertSessionHas('error', 'Connexion à Pax8 : identifiants invalides ou expirés (HTTP 401, access_denied).');
    }

    public function test_scheduled_command_only_syncs_due_integrations(): void
    {
        $due = $this->configurePax8();
        $due->forceFill(['last_synced_at' => now()->subDays(2)])->save();
        $this->fakePax8([]);

        $recent = Integration::query()->forceCreate(['provider' => 'sophos', 'is_enabled' => true, 'frequency' => 'daily', 'last_synced_at' => now()->subHour()]);
        $manual = Integration::query()->forceCreate(['provider' => 'eset', 'is_enabled' => true, 'frequency' => 'manual']);
        $disabled = Integration::query()->forceCreate(['provider' => 'acronis', 'is_enabled' => false, 'frequency' => 'daily']);

        // Exécution directe, sans file d'attente.
        $this->artisan('techindex:integrations-sync')->assertSuccessful();

        $this->assertSame('success', $due->runs()->sole()->status->value);
        $this->assertSame(0, $recent->runs()->count() + $manual->runs()->count() + $disabled->runs()->count());
    }

    public function test_a_stalled_run_is_reported_instead_of_waiting_forever(): void
    {
        $integration = $this->configurePax8();
        $admin = $this->admin();
        $queued = $integration->runs()->create(['trigger' => 'manual', 'status' => 'queued']);
        $queued->forceFill(['created_at' => now()->subMinutes(15)])->save();

        $this->actingAs($admin)->get('/admin/integrations/pax8')
            ->assertInertia(fn ($page) => $page->where('running', false)->where('runs.0.status', 'failed'));

        $this->assertSame('failed', $queued->fresh()->status->value);
        $this->assertSame('failed', $integration->fresh()->last_status->value);

        // Une exécution récente reste suivie normalement.
        $integration->runs()->create(['trigger' => 'manual', 'status' => 'running', 'started_at' => now()->subMinute()]);
        $this->actingAs($admin)->get('/admin/integrations/pax8')->assertInertia(fn ($page) => $page->where('running', true));
    }

    public function test_each_flash_message_has_its_own_identifier(): void
    {
        $this->configurePax8();
        $admin = $this->admin();

        // Le message de l'enregistrement est affiché une fois, puis plus de message.
        $this->actingAs($admin)->get('/admin/integrations/pax8')
            ->assertInertia(fn ($page) => $page->where('flash.success', 'Configuration de l\'intégration enregistrée.'));
        $this->actingAs($admin)->get('/admin/integrations/pax8')
            ->assertInertia(fn ($page) => $page->where('flash.id', null)->where('flash.success', null));

        $first = $this->actingAs($admin)->withSession(['success' => 'Message'])->get('/admin/integrations/pax8');
        $second = $this->actingAs($admin)->withSession(['success' => 'Message'])->get('/admin/integrations/pax8');

        $id = fn ($response) => $response->viewData('page')['props']['flash']['id'];
        $this->assertNotNull($id($first));
        $this->assertNotSame($id($first), $id($second));
    }

    public function test_key_rotation_reseals_integration_credentials(): void
    {
        $integration = $this->configurePax8();
        $oldKeyId = $integration->credentials_key_id;

        config(['techindex.secrets.key' => Keyring::generate(), 'techindex.secrets.previous_keys' => [config('techindex.secrets.key')]]);
        $this->app->forgetInstance(Keyring::class);
        $this->app->forgetInstance(SecretVault::class);
        $this->app->forgetInstance(IntegrationSecrets::class);
        $this->app->forgetInstance(CredentialSecrets::class);

        $this->artisan('techindex:secrets-rotate')->assertSuccessful();

        $integration->refresh();
        $this->assertNotSame($oldKeyId, $integration->credentials_key_id);
        $this->assertSame(self::PAX8_SECRET, app(IntegrationSecrets::class)->read($integration)['client_secret']);
    }
}
