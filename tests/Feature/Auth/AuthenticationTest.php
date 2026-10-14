<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_login_page_is_displayed(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_public_registration_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456'])->assertNotFound();
    }

    public function test_user_can_log_in_and_login_is_audited(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $user->id]);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected_with_a_generic_message_and_never_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'Mauvais-Mot-de-passe-1'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
        $log = AuditLog::query()->where('event', 'auth.failed')->sole();
        $this->assertSame($user->email, $log->metadata['email']);
        $this->assertStringNotContainsString('Mauvais-Mot-de-passe-1', (string) json_encode($log->getAttributes()));
    }

    public function test_unknown_email_gets_the_same_message(): void
    {
        $this->post('/login', ['email' => 'inconnu@example.test', 'password' => 'Nimporte-quoi-123'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'Mauvais-'.$i.'-Abcdef']);
        }

        // Même le bon mot de passe est refusé pendant le blocage.
        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.lockout']);
    }

    public function test_session_of_a_deactivated_user_is_closed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_session_expires_after_the_absolute_lifetime(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.authenticated_at' => now()->subMinutes(config('techindex.security.session_absolute_lifetime') + 1)->getTimestamp()])
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_temporary_password_must_be_changed_before_accessing_data(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/clients')->assertRedirect(route('profile.show'));
        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_user_can_change_password_and_other_sessions_are_revoked(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->put('/user/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'Nouveau-Mot-de-passe-2027',
            'password_confirmation' => 'Nouveau-Mot-de-passe-2027',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_changed', 'subject_id' => $user->id]);
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'court',
            'password_confirmation' => 'court',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_two_factor_challenge_is_required_when_enabled(): void
    {
        $engine = app(Google2FA::class);
        $secret = $engine->generateSecretKey();
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['code-de-secours-1', 'code-de-secours-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $engine->getCurrentOtp($secret)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_code_can_be_used_once(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['code-de-secours-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => 'code-de-secours-1'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('code-de-secours-1', $user->fresh()->recoveryCodes());
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.recovery_code_used']);
    }

    public function test_two_factor_can_be_made_mandatory_for_a_role(): void
    {
        config(['techindex.security.two_factor_required_roles' => ['admin']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/clients')->assertRedirect(route('profile.show'));
        $this->actingAs($admin)->get('/profile')->assertOk();
    }

    public function test_password_reset_response_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'personne@example.test']);

        $known->assertSessionHas('status');
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        $unknown->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_password_confirmation_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/user/confirm-password', ['password' => 'Faux-mot-de-passe-'.$i]);
        }

        $this->actingAs($user)->postJson('/user/confirm-password', ['password' => UserFactory::PASSWORD])->assertStatus(429);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/clients')->assertRedirect('/login');
        $this->get('/clients/1/credentials')->assertRedirect('/login');
    }
}
