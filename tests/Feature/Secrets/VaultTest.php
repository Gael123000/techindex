<?php

namespace Tests\Feature\Secrets;

use App\Enums\AccessType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Credential;
use App\Models\Equipment;
use App\Models\User;
use App\Models\VaultFolder;
use App\Services\Vault\VaultLock;
use Tests\Concerns\InteractsWithPasskeys;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

/**
 * Gestionnaire de mots de passe : verrou par clé d'accès commun aux fiches,
 * ouvert 5 minutes sans prolongation, même source de données que les fiches,
 * rangement en dossiers limité au client.
 */
class VaultTest extends TestCase
{
    use InteractsWithPasskeys;

    private const SECRET = 'C0ffre-Valeur-Secr3te!';

    private Client $client;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePasskeys();
        $this->client = Client::factory()->create();
        $this->technician = $this->userFor($this->client, UserRole::Technician);
    }

    /**
     * Utilisateur avec clé d'accès et coffre déverrouillé, sans autre confirmation en session.
     */
    private function unlocked(?User $user = null): FakeAuthenticator
    {
        $user ??= $this->technician;
        $authenticator = $this->registerPasskey($user);
        $this->flushSession();
        $this->unlockVault($user, $authenticator);

        return $authenticator;
    }

    private function credential(array $attributes = []): Credential
    {
        $this->actingAs($this->technician)->post("/clients/{$this->client->id}/credentials", [
            'label' => 'Console pare-feu',
            'access_type' => AccessType::Web->value,
            'username' => 'admin',
            'secret' => self::SECRET,
            ...$attributes,
        ])->assertSessionHasNoErrors();

        return Credential::query()->latest('id')->firstOrFail();
    }

    public function test_the_vault_is_locked_until_a_passkey_is_verified(): void
    {
        $credential = $this->credential();

        $this->actingAs($this->technician)->get('/vault')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Vault/Locked')->where('hasPasskeys', false));
        $this->get("/vault/clients/{$this->client->id}")->assertInertia(fn ($page) => $page->component('Vault/Locked'));
        $this->postJson(route('clients.credentials.reveal', [$this->client, $credential]), ['purpose' => 'reveal'])
            ->assertStatus(423)
            ->assertJsonPath('vault_locked', true);
        $this->getJson(route('vault.unlock.options'))->assertStatus(422);
    }

    public function test_a_password_confirmation_does_not_unlock_the_vault(): void
    {
        $this->registerPasskey($this->technician);

        $this->actingAs($this->technician)->withRecentConfirmation()->get('/vault')
            ->assertInertia(fn ($page) => $page->component('Vault/Locked')->where('hasPasskeys', true));
    }

    public function test_unlocking_shows_credentials_but_never_their_secret(): void
    {
        $this->credential();
        $this->unlocked();

        $response = $this->get("/vault/clients/{$this->client->id}")->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Vault/Index')->has('credentials.data', 1)->where('credentials.data.0.label', 'Console pare-feu'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertTrue(AuditLog::query()->where('event', 'vault.unlocked')->where('user_id', $this->technician->id)->exists());
    }

    public function test_a_forged_unlock_is_refused_and_audited(): void
    {
        $this->registerPasskey($this->technician);
        $this->flushSession();
        $stranger = new FakeAuthenticator;

        $options = $this->actingAs($this->technician)->getJson(route('vault.unlock.options'))->json('options');
        $this->postJson(route('vault.unlock'), ['credential' => $stranger->get($options)])->assertStatus(422);

        $this->get('/vault')->assertInertia(fn ($page) => $page->component('Vault/Locked'));
        $this->assertTrue(AuditLog::query()->where('event', 'vault.unlock_failed')->exists());
    }

    public function test_credentials_are_shared_between_equipment_pages_and_the_vault(): void
    {
        $equipment = Equipment::factory()->for($this->client)->create();
        $folder = VaultFolder::query()->forceCreate(['client_id' => $this->client->id, 'name' => 'Réseau']);

        // Créé depuis la fiche équipement : visible dans le gestionnaire, sous l'équipement.
        $this->credential(['label' => 'iDRAC', 'equipment_id' => $equipment->id]);
        // Créé depuis le gestionnaire, dans un dossier : visible sur la fiche équipement.
        $this->credential(['label' => 'SSH', 'equipment_id' => $equipment->id, 'folder_id' => $folder->id]);

        $this->unlocked();

        $this->get("/vault/clients/{$this->client->id}?equipment={$equipment->id}")
            ->assertInertia(fn ($page) => $page->has('credentials.data', 2)->where('tree.equipment.0.count', 2));
        $this->get("/vault/clients/{$this->client->id}?folder={$folder->id}")
            ->assertInertia(fn ($page) => $page->has('credentials.data', 1)->where('credentials.data.0.label', 'SSH'));
        $this->get("/clients/{$this->client->id}/equipment/{$equipment->id}")
            ->assertInertia(fn ($page) => $page->has('credentials', 2));

        // Une modification depuis la fiche (sans champ dossier) conserve le rangement.
        $ssh = Credential::query()->where('label', 'SSH')->sole();
        $this->put("/clients/{$this->client->id}/credentials/{$ssh->id}", ['label' => 'SSH root', 'access_type' => 'ssh', 'equipment_id' => $equipment->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($folder->id, $ssh->fresh()->folder_id);
    }

    public function test_the_vault_relocks_five_minutes_after_unlocking_even_when_active(): void
    {
        $this->freezeTime();
        $credential = $this->credential();
        $this->unlocked();
        $this->get('/vault')->assertInertia(fn ($page) => $page->component('Vault/Index')->where('vault.expires_in', VaultLock::DURATION));

        // Une activité régulière ne prolonge pas l'ouverture.
        for ($minute = 1; $minute <= 4; $minute++) {
            $this->travel(60)->seconds();
            $this->get('/vault')->assertInertia(fn ($page) => $page->component('Vault/Index')->where('vault.expires_in', VaultLock::DURATION - 60 * $minute));
            $this->postJson(route('clients.credentials.reveal', [$this->client, $credential]), ['purpose' => 'reveal'])->assertOk();
        }

        $this->travel(VaultLock::DURATION - 240)->seconds();
        $this->get('/vault')->assertInertia(fn ($page) => $page->component('Vault/Locked')->where('lockMinutes', 5)->where('vault.expires_in', 0));
        $this->postJson(route('clients.credentials.reveal', [$this->client, $credential]), ['purpose' => 'reveal'])
            ->assertStatus(423)
            ->assertJsonPath('vault_locked', true)
            ->assertJsonMissingPath('secret');
    }

    public function test_secrets_on_an_equipment_page_require_the_passkey_unlock_shared_with_the_vault(): void
    {
        $equipment = Equipment::factory()->for($this->client)->create();
        $credential = $this->credential(['equipment_id' => $equipment->id]);
        $authenticator = $this->registerPasskey($this->technician);
        $reveal = fn () => $this->postJson(route('clients.credentials.reveal', [$this->client, $credential]), ['purpose' => 'copy']);

        // Mot de passe TechIndex confirmé (registerPasskey) : la fiche reste verrouillée.
        $this->get("/clients/{$this->client->id}/equipment/{$equipment->id}")->assertInertia(fn ($page) => $page
            ->where('vault.expires_in', 0)
            ->where('credentials.0.reveal_url', route('clients.credentials.reveal', [$this->client, $credential])));
        $reveal()->assertStatus(423)->assertJsonPath('vault_locked', true)->assertJsonMissingPath('secret');

        // Déverrouillé depuis la fiche : le gestionnaire est ouvert aussi, pour la même durée.
        $this->unlockVault($this->technician, $authenticator);
        $reveal()->assertOk()->assertJsonPath('secret', self::SECRET);
        $this->get('/vault')->assertInertia(fn ($page) => $page->component('Vault/Index'));
        $this->assertTrue(AuditLog::query()->where('event', 'credential.secret_copied')->where('subject_id', $credential->id)->exists());

        // 5 minutes plus tard, la fiche redemande la clé d'accès.
        $this->travel(VaultLock::DURATION)->seconds();
        $this->get("/clients/{$this->client->id}/equipment/{$equipment->id}")->assertInertia(fn ($page) => $page->where('vault.expires_in', 0));
        $reveal()->assertStatus(423)->assertJsonPath('vault_locked', true);
    }

    public function test_locking_clears_the_unlocked_state(): void
    {
        $credential = $this->credential();
        $this->unlocked();

        $this->post(route('vault.lock'))->assertRedirect(route('vault.index'));
        $this->get('/vault')->assertInertia(fn ($page) => $page->component('Vault/Locked'));

        // Verrouillage depuis une fiche (appel JSON) : les secrets sont refusés aussitôt.
        $this->unlocked();
        $this->postJson(route('vault.lock'))->assertOk()->assertJsonPath('locked', true);
        $this->postJson(route('clients.credentials.reveal', [$this->client, $credential]), ['purpose' => 'reveal'])->assertStatus(423);
    }

    public function test_reveal_from_the_vault_requires_the_secret_permission_and_is_audited(): void
    {
        $credential = $this->credential();
        $reader = $this->userFor($this->client, UserRole::Reader);
        $this->unlocked($reader);

        $this->actingAs($reader)->postJson(route('clients.credentials.reveal', [$this->client, $credential]), ['purpose' => 'reveal'])->assertForbidden();

        $this->flushSession();
        $this->unlocked();
        $response = $this->postJson(route('clients.credentials.reveal', [$this->client, $credential]), ['purpose' => 'copy'])
            ->assertOk()
            ->assertJsonPath('secret', self::SECRET);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $log = AuditLog::query()->where('event', 'credential.secret_copied')->sole();
        $this->assertStringNotContainsString(self::SECRET, json_encode($log->toArray()));
    }

    public function test_an_unassigned_client_is_not_found_even_when_unlocked(): void
    {
        $other = Client::factory()->create();
        $foreign = Credential::query()->forceCreate(['client_id' => $other->id, 'label' => 'Autre', 'access_type' => 'web']);

        $this->actingAs($this->technician)->get("/vault/clients/{$other->id}")->assertNotFound();

        $this->unlocked();
        $this->get("/vault/clients/{$other->id}")->assertNotFound();
        $this->postJson(route('clients.credentials.reveal', [$this->client, $foreign]), ['purpose' => 'reveal'])->assertNotFound();
        $this->get('/vault')->assertInertia(fn ($page) => $page->has('clients', 1)->where('clients.0.id', $this->client->id));
    }

    public function test_folders_and_moves_are_limited_to_the_client(): void
    {
        $other = Client::factory()->create();
        $this->assign($this->technician, $other);
        $foreignFolder = VaultFolder::query()->forceCreate(['client_id' => $other->id, 'name' => 'Étranger']);
        $credential = $this->credential();
        $this->unlocked();

        $this->get("/vault/clients/{$this->client->id}?folder={$foreignFolder->id}")->assertNotFound();
        $this->put(route('vault.clients.folders.update', [$this->client, $foreignFolder]), ['name' => 'X'])->assertNotFound();
        $this->post(route('vault.clients.credentials.move', $this->client), ['credential_ids' => [$credential->id], 'folder_id' => $foreignFolder->id])
            ->assertSessionHasErrors('folder_id');
        $this->post("/clients/{$this->client->id}/credentials", ['label' => 'X', 'access_type' => 'web', 'folder_id' => $foreignFolder->id])
            ->assertSessionHasErrors('folder_id');
        $this->post(route('vault.clients.folders.store', $this->client), ['name' => 'Sous', 'parent_id' => $foreignFolder->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_folder_rules_names_cycles_and_deletion(): void
    {
        $this->unlocked();
        $store = fn (string $name, ?int $parent = null) => $this->post(route('vault.clients.folders.store', $this->client), ['name' => $name, 'parent_id' => $parent]);

        $store('Réseau')->assertSessionHasNoErrors();
        $network = VaultFolder::query()->where('name', 'Réseau')->sole();
        $store('réseau')->assertSessionHasErrors('name');
        $store('a/b')->assertSessionHasErrors('name');
        $store('Pare-feu', $network->id)->assertSessionHasNoErrors();
        $firewall = VaultFolder::query()->where('name', 'Pare-feu')->sole();

        // Un dossier ne peut pas être déplacé dans son propre sous-dossier.
        $this->put(route('vault.clients.folders.update', [$this->client, $network]), ['name' => 'Réseau', 'parent_id' => $firewall->id])
            ->assertSessionHasErrors('parent_id');

        // Supprimer un dossier ne supprime aucun accès : le contenu remonte.
        $credential = $this->credential(['folder_id' => $network->id]);
        $this->unlocked();
        $this->delete(route('vault.clients.folders.destroy', [$this->client, $network]))->assertSessionHasNoErrors();

        $this->assertNull($credential->fresh()->folder_id);
        $this->assertNull($firewall->fresh()->parent_id);
        $this->assertNotNull($credential->fresh()->secret_ciphertext);
    }

    public function test_a_reader_cannot_organise_the_vault(): void
    {
        $credential = $this->credential();
        $reader = $this->userFor($this->client, UserRole::Reader);
        $this->unlocked($reader);

        $this->actingAs($reader)->post(route('vault.clients.folders.store', $this->client), ['name' => 'Perso'])->assertForbidden();
        $this->post(route('vault.clients.credentials.move', $this->client), ['credential_ids' => [$credential->id], 'folder_id' => null])->assertForbidden();
        $this->assertSame(0, VaultFolder::query()->count());
    }

    public function test_search_covers_metadata_but_never_secrets(): void
    {
        $this->credential(['label' => 'Routeur principal']);
        $this->unlocked();

        $this->get('/vault?q=Routeur')->assertInertia(fn ($page) => $page->has('search.results', 1));
        $this->get('/vault?q='.urlencode(self::SECRET))->assertInertia(fn ($page) => $page->has('search.results', 0));
        $this->get("/vault/clients/{$this->client->id}?q=".urlencode(self::SECRET))->assertInertia(fn ($page) => $page->has('credentials.data', 0));
    }
}
