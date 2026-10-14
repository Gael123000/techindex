<?php

namespace Tests\Feature\Auth;

use App\Enums\AccessType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Credential;
use App\Models\Passkey;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\InteractsWithPasskeys;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

/**
 * Clés d'accès (WebAuthn) : les réponses sont produites par un authentificateur
 * logiciel et vérifiées par la même bibliothèque qu'en production.
 */
class PasskeyTest extends TestCase
{
    use InteractsWithPasskeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePasskeys();
    }

    /**
     * Ferme la session ouverte par actingAs() pour tester la connexion.
     */
    private function signOut(): void
    {
        Auth::guard('web')->logout();
        $this->flushSession();
    }

    private function loginWithPassword(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_adding_a_passkey_requires_a_recent_identity_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('profile.passkeys.options'))->assertStatus(423);
        $this->postJson(route('profile.passkeys.store'), ['name' => 'X', 'credential' => (new FakeAuthenticator)->create(['challenge' => 'abc'])])->assertStatus(423);

        $this->assertSame(0, Passkey::query()->count());
    }

    public function test_a_passkey_is_registered_and_only_its_public_key_is_kept(): void
    {
        $user = User::factory()->create();

        $this->registerPasskey($user, 'PC du bureau');

        $passkey = Passkey::query()->sole();
        $this->assertSame($user->id, $passkey->user_id);
        $this->assertSame('PC du bureau', $passkey->name);
        $this->assertArrayHasKey('credentialPublicKey', $passkey->credential);
        $this->assertTrue($passkey->credential['uvInitialized']);
        $this->assertArrayNotHasKey('credential', $passkey->toArray());
        $this->assertTrue(AuditLog::query()->where('event', 'auth.passkey_added')->where('user_id', $user->id)->exists());

        // Le profil liste la clé sans jamais transmettre la clé publique.
        $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page
            ->component('Profile/Show')
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'PC du bureau')
            ->missing('passkeys.0.credential'));
    }

    public function test_a_forged_or_replayed_registration_is_refused(): void
    {
        $user = User::factory()->create();
        $authenticator = new FakeAuthenticator(origin: 'https://site-malveillant.example');

        $options = $this->actingAs($user)->withRecentConfirmation()->getJson(route('profile.passkeys.options'))->json('options');
        $response = $authenticator->create($options);
        $this->postJson(route('profile.passkeys.store'), ['name' => 'Piège', 'credential' => $response])->assertStatus(422);

        // Le défi est consommé : la même réponse ne peut pas être rejouée.
        $authenticator->origin = 'http://localhost';
        $this->postJson(route('profile.passkeys.store'), ['name' => 'Piège', 'credential' => $authenticator->create($options)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credential');

        $this->assertSame(0, Passkey::query()->count());
    }

    public function test_the_passkey_is_required_as_second_factor_after_the_password(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->registerPasskey($user);
        $this->signOut();

        $this->loginWithPassword($user);

        $this->get(route('two-factor.login'))->assertInertia(fn ($page) => $page
            ->component('Auth/TwoFactorChallenge')
            ->where('methods.passkey', true)
            ->where('methods.totp', false));

        $options = $this->getJson(route('two-factor.passkey.options'))->assertOk()->json('options');
        $this->assertCount(1, $options['allowCredentials']);
        $this->assertSame('required', $options['userVerification']);

        $this->postJson(route('two-factor.passkey.store'), ['credential' => $authenticator->get($options)])
            ->assertOk()
            ->assertJsonPath('redirect', url('/dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(AuditLog::query()->where('event', 'auth.passkey_used')->where('user_id', $user->id)->exists());
        $this->assertNotNull(Passkey::query()->sole()->last_used_at);
    }

    public function test_a_passkey_cannot_replace_the_password(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->registerPasskey($user);
        $this->signOut();

        // Sans mot de passe vérifié, aucune vérification par clé d'accès n'est possible.
        $this->getJson(route('two-factor.passkey.options'))->assertStatus(401);
        $this->postJson(route('two-factor.passkey.store'), ['credential' => $authenticator->get(['challenge' => 'abc'])])->assertStatus(401);

        $this->assertGuest();
    }

    public function test_the_passkey_of_another_user_is_refused(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alicesKey = $this->registerPasskey($alice);
        $this->registerPasskey($bob);
        $this->signOut();

        $this->loginWithPassword($bob);
        $options = $this->getJson(route('two-factor.passkey.options'))->json('options');

        $this->postJson(route('two-factor.passkey.store'), ['credential' => $alicesKey->get($options)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credential');

        $this->assertGuest();
        $this->assertTrue(AuditLog::query()->where('event', 'auth.passkey_failed')->exists());
    }

    public function test_user_presence_without_verification_is_refused(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->registerPasskey($user);
        $this->signOut();

        $this->loginWithPassword($user);
        $options = $this->getJson(route('two-factor.passkey.options'))->json('options');
        $authenticator->userVerified = false;

        $this->postJson(route('two-factor.passkey.store'), ['credential' => $authenticator->get($options)])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_totp_code_for_a_passkey_only_account_is_refused_cleanly(): void
    {
        $user = User::factory()->create();
        $this->registerPasskey($user);
        $this->signOut();

        $this->loginWithPassword($user);

        $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect(route('two-factor.login'));
        $this->post('/two-factor-challenge', ['recovery_code' => 'code-invente'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_a_passkey_satisfies_a_mandatory_second_factor(): void
    {
        config(['techindex.security.two_factor_required_roles' => ['technician']]);

        $without = User::factory()->create();
        $this->actingAs($without)->get('/dashboard')->assertRedirect(route('profile.show'));

        $with = User::factory()->create();
        $this->registerPasskey($with);
        $this->actingAs($with)->get('/dashboard')->assertOk();
    }

    public function test_a_passkey_can_confirm_identity_before_revealing_a_secret(): void
    {
        $client = Client::factory()->create();
        $user = $this->userFor($client, UserRole::Technician);
        $authenticator = $this->registerPasskey($user);
        $this->flushSession();

        $this->actingAs($user)->post("/clients/{$client->id}/credentials", [
            'label' => 'Console', 'access_type' => AccessType::Web->value, 'secret' => 'Valeur-Secrete-42',
        ])->assertSessionHasNoErrors();
        $credential = Credential::query()->sole();

        $reveal = fn () => $this->postJson(route('clients.credentials.reveal', [$client, $credential]), ['purpose' => 'reveal']);
        $reveal()->assertStatus(423);

        $options = $this->getJson(route('security.passkey-confirmation.options'))->assertOk()->json('options');
        $this->postJson(route('security.passkey-confirmation.store'), ['credential' => $authenticator->get($options)])->assertOk();

        $reveal()->assertOk()->assertJsonPath('secret', 'Valeur-Secrete-42');
    }

    public function test_a_user_cannot_rename_or_delete_the_passkey_of_someone_else(): void
    {
        $owner = User::factory()->create();
        $this->registerPasskey($owner);
        $passkey = Passkey::query()->sole();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->withRecentConfirmation()->put(route('profile.passkeys.update', $passkey), ['name' => 'Volée'])->assertNotFound();
        $this->delete(route('profile.passkeys.destroy', $passkey))->assertNotFound();

        $this->assertSame('Clé de test', $passkey->fresh()->name);
    }

    public function test_owner_can_delete_a_passkey_after_confirmation(): void
    {
        $user = User::factory()->create();
        $this->registerPasskey($user);
        $passkey = Passkey::query()->sole();
        $this->flushSession();

        $this->actingAs($user)->delete(route('profile.passkeys.destroy', $passkey))->assertRedirect(route('password.confirm'));
        $this->assertSame(1, Passkey::query()->count());

        $this->withRecentConfirmation()->delete(route('profile.passkeys.destroy', $passkey))->assertSessionHasNoErrors();
        $this->assertSame(0, Passkey::query()->count());
        $this->assertTrue(AuditLog::query()->where('event', 'auth.passkey_removed')->exists());
    }

    public function test_resetting_two_factor_also_removes_passkeys(): void
    {
        $user = User::factory()->create();
        $this->registerPasskey($user);

        $this->actingAs($this->admin())->post(route('admin.users.reset-two-factor', $user))->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->hasPasskeysEnabled());
        $this->assertTrue(AuditLog::query()->where('event', 'auth.passkeys_reset')->exists());
    }
}
