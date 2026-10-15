<?php

namespace Tests\Feature\Secrets;

use App\Enums\AccessType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Credential;
use App\Models\Equipment;
use App\Models\User;
use App\Secrets\Contracts\SecretVault;
use App\Secrets\CredentialSecrets;
use App\Secrets\Exceptions\SecretVaultException;
use App\Secrets\Keyring;
use App\Secrets\LocalSecretVault;
use App\Secrets\SealedSecret;
use App\Services\Vault\VaultLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialSecretsTest extends TestCase
{
    private const SECRET = 'Tr3s-S3cret!Valeur#Unique';

    private Client $client;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->technician = $this->userFor($this->client, UserRole::Technician);
    }

    private function storeCredential(?string $secret = self::SECRET): Credential
    {
        $this->actingAs($this->technician)->post("/clients/{$this->client->id}/credentials", [
            'label' => 'Console pare-feu',
            'access_type' => AccessType::Web->value,
            'username' => 'admin',
            'secret' => $secret,
            'host' => 'fw-01',
            'port' => 443,
        ])->assertSessionHasNoErrors();

        return Credential::query()->latest('id')->firstOrFail();
    }

    public function test_secret_is_encrypted_at_rest_and_never_in_clear(): void
    {
        $credential = $this->storeCredential();
        $row = DB::table('credentials')->where('id', $credential->id)->first();

        $this->assertNotNull($row->secret_ciphertext);
        $this->assertStringNotContainsString(self::SECRET, $row->secret_ciphertext);
        $this->assertSame(app(Keyring::class)->currentId(), $row->secret_key_id);
        $this->assertSame(self::SECRET, app(SecretVault::class)->unseal(new SealedSecret($row->secret_ciphertext, $row->secret_key_id)));

        // Recherche brute dans toutes les colonnes texte de la table.
        $this->assertStringNotContainsString(self::SECRET, json_encode(DB::table('credentials')->get()));
    }

    public function test_pages_never_contain_the_secret_or_its_ciphertext(): void
    {
        $credential = $this->storeCredential();
        $equipment = Equipment::factory()->for($this->client)->create();
        $credential->update(['equipment_id' => $equipment->id]);
        $ciphertext = DB::table('credentials')->where('id', $credential->id)->value('secret_ciphertext');

        foreach (["/clients/{$this->client->id}/credentials", "/clients/{$this->client->id}/equipment/{$equipment->id}"] as $url) {
            $content = $this->actingAs($this->technician)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::SECRET, $content);
            $this->assertStringNotContainsString($ciphertext, $content);
            $this->assertStringNotContainsString('secret_ciphertext', $content);
        }

        $this->assertArrayNotHasKey('secret_ciphertext', $credential->fresh()->toArray());
    }

    public function test_reveal_requires_a_passkey_unlock_of_less_than_five_minutes(): void
    {
        $credential = $this->storeCredential();
        $url = "/clients/{$this->client->id}/credentials/{$credential->id}/reveal";

        $this->actingAs($this->technician)->postJson($url, ['purpose' => 'reveal'])
            ->assertStatus(423)
            ->assertJsonPath('vault_locked', true)
            ->assertJsonMissingPath('secret');

        // Un mot de passe confirmé ne suffit pas.
        $this->actingAs($this->technician)->withRecentConfirmation()->postJson($url, ['purpose' => 'reveal'])
            ->assertStatus(423)
            ->assertJsonMissingPath('secret');

        // Déverrouillage par un autre utilisateur dans la même session : refusé.
        $other = $this->userFor($this->client, UserRole::Technician);
        $this->actingAs($this->technician)->withUnlockedVault($other)->postJson($url, ['purpose' => 'reveal'])->assertStatus(423);

        // Déverrouillage de plus de 5 minutes, même avec une activité entre-temps.
        $this->actingAs($this->technician)->withUnlockedVault($this->technician)->postJson($url, ['purpose' => 'reveal'])->assertOk();
        $this->travel(VaultLock::DURATION - 10)->seconds();
        $this->postJson($url, ['purpose' => 'reveal'])->assertOk();
        $this->travel(11)->seconds();
        $this->postJson($url, ['purpose' => 'reveal'])->assertStatus(423)->assertJsonMissingPath('secret');
    }

    public function test_reveal_returns_the_secret_without_caching_and_is_audited_without_value(): void
    {
        $credential = $this->storeCredential();

        $response = $this->actingAs($this->technician)->withUnlockedVault($this->technician)
            ->postJson("/clients/{$this->client->id}/credentials/{$credential->id}/reveal", ['purpose' => 'copy'])
            ->assertOk()
            ->assertJson(['secret' => self::SECRET]);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $log = AuditLog::query()->where('event', 'credential.secret_copied')->sole();
        $this->assertSame($this->technician->id, $log->user_id);
        $this->assertSame($this->client->id, $log->client_id);
        $this->assertStringNotContainsString(self::SECRET, json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_reader_cannot_reveal_secrets_by_default(): void
    {
        $credential = $this->storeCredential();
        $reader = $this->userFor($this->client, UserRole::Reader);

        $this->actingAs($reader)->get("/clients/{$this->client->id}/credentials")->assertOk()
            ->assertInertia(fn ($page) => $page->where('credentials.data.0.reveal_url', null));

        $this->actingAs($reader)->withUnlockedVault($reader)
            ->postJson("/clients/{$this->client->id}/credentials/{$credential->id}/reveal", ['purpose' => 'reveal'])
            ->assertForbidden()
            ->assertJsonMissingPath('secret');
    }

    public function test_secret_of_another_client_is_not_reachable(): void
    {
        $credential = $this->storeCredential();
        $otherClient = Client::factory()->create();
        $stranger = $this->userFor($otherClient, UserRole::Manager);

        $this->actingAs($stranger)->withUnlockedVault($stranger)
            ->postJson("/clients/{$this->client->id}/credentials/{$credential->id}/reveal", ['purpose' => 'reveal'])
            ->assertNotFound();

        $this->actingAs($stranger)->withUnlockedVault($stranger)
            ->postJson("/clients/{$otherClient->id}/credentials/{$credential->id}/reveal", ['purpose' => 'reveal'])
            ->assertNotFound();
    }

    public function test_user_without_secret_permission_cannot_set_a_secret(): void
    {
        $editor = $this->userFor($this->client, UserRole::Technician, ['can_view_secrets' => false]);

        $this->actingAs($editor)->post("/clients/{$this->client->id}/credentials", [
            'label' => 'Accès', 'access_type' => 'ssh', 'secret' => 'tentative',
        ])->assertForbidden();

        $this->actingAs($editor)->post("/clients/{$this->client->id}/credentials", [
            'label' => 'Accès sans secret', 'access_type' => 'ssh',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Credential::query()->where('label', 'Accès sans secret')->value('secret_ciphertext'));
    }

    public function test_update_keeps_secret_when_empty_and_history_never_stores_values(): void
    {
        $credential = $this->storeCredential();
        $url = "/clients/{$this->client->id}/credentials/{$credential->id}";

        $this->actingAs($this->technician)->put($url, ['label' => 'Console renommée', 'access_type' => 'web', 'secret' => ''])->assertSessionHasNoErrors();
        $this->assertTrue($credential->fresh()->has_secret);

        $this->actingAs($this->technician)->put($url, ['label' => 'Console renommée', 'access_type' => 'web', 'secret' => 'Nouvelle-Valeur-99'])->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->put($url, ['label' => 'Console renommée', 'access_type' => 'web', 'clear_secret' => true])->assertSessionHasNoErrors();
        $this->assertFalse($credential->fresh()->has_secret);

        $history = json_encode(AuditLog::query()->where('subject_type', 'credential')->get()->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::SECRET, $history);
        $this->assertStringNotContainsString('Nouvelle-Valeur-99', $history);
        $this->assertStringContainsString('[masqué]', $history);
        $this->assertStringContainsString('Console renommée', $history);
    }

    public function test_secret_is_never_flashed_back_after_a_validation_error(): void
    {
        $this->actingAs($this->technician)
            ->from("/clients/{$this->client->id}/credentials")
            ->post("/clients/{$this->client->id}/credentials", ['label' => '', 'access_type' => 'web', 'secret' => 'Ne-Pas-Reafficher-1'])
            ->assertSessionHasErrors('label');

        $this->assertStringNotContainsString('Ne-Pas-Reafficher-1', json_encode(session()->all()));
    }

    public function test_reveals_are_rate_limited(): void
    {
        $credential = $this->storeCredential();
        $limit = (int) config('techindex.secrets.reveal_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($this->technician)->withUnlockedVault($this->technician)
                ->postJson("/clients/{$this->client->id}/credentials/{$credential->id}/reveal", ['purpose' => 'reveal'])->assertOk();
        }

        $this->actingAs($this->technician)->withUnlockedVault($this->technician)
            ->postJson("/clients/{$this->client->id}/credentials/{$credential->id}/reveal", ['purpose' => 'reveal'])->assertStatus(429);
    }

    public function test_key_rotation_reencrypts_secrets_with_the_new_key(): void
    {
        $credential = $this->storeCredential();
        $oldKey = config('techindex.secrets.key');
        $oldId = app(Keyring::class)->currentId();

        // Nouvelle clé courante, ancienne clé conservée en lecture.
        config(['techindex.secrets.key' => Keyring::generate(), 'techindex.secrets.previous_keys' => [$oldKey]]);
        $this->app->forgetInstance(Keyring::class);
        $this->app->forgetInstance(SecretVault::class);
        $this->app->forgetInstance(CredentialSecrets::class);

        $this->assertSame(self::SECRET, $this->revealDirectly($credential));

        $result = app(CredentialSecrets::class)->rotate();

        $this->assertSame(['rotated' => 1, 'failed' => 0], $result);
        $this->assertNotSame($oldId, $credential->fresh()->secret_key_id);

        // Après rotation, l'ancienne clé n'est plus nécessaire.
        config(['techindex.secrets.previous_keys' => []]);
        $this->app->forgetInstance(Keyring::class);
        $this->app->forgetInstance(SecretVault::class);
        $this->assertSame(self::SECRET, $this->revealDirectly($credential));
    }

    public function test_a_missing_key_fails_safely(): void
    {
        $credential = $this->storeCredential();

        config(['techindex.secrets.key' => Keyring::generate(), 'techindex.secrets.previous_keys' => []]);
        $this->app->forgetInstance(Keyring::class);
        $this->app->forgetInstance(SecretVault::class);
        $this->app->forgetInstance(CredentialSecrets::class);

        $this->actingAs($this->technician)->withUnlockedVault($this->technician)
            ->postJson("/clients/{$this->client->id}/credentials/{$credential->id}/reveal", ['purpose' => 'reveal'])
            ->assertStatus(409)
            ->assertJsonMissingPath('secret');

        $this->assertDatabaseHas('audit_logs', ['event' => 'credential.secret_revealed', 'subject_id' => $credential->id]);
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $vault = new LocalSecretVault(new Keyring(Keyring::generate(), null));
        $sealed = $vault->seal('valeur');
        $payload = json_decode(base64_decode($sealed->ciphertext), true);
        $payload['value'] = base64_encode('altéré');

        $this->expectException(SecretVaultException::class);
        $vault->unseal(new SealedSecret(base64_encode(json_encode($payload)), $sealed->keyId));
    }

    private function revealDirectly(Credential $credential): string
    {
        $credential = $credential->fresh();

        return app(SecretVault::class)->unseal(new SealedSecret($credential->secret_ciphertext, $credential->secret_key_id));
    }
}
