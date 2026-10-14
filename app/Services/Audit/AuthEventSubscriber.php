<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Journalise les événements d'authentification. Les identifiants saisis ne
 * sont jamais enregistrés : seule l'adresse e-mail tentée l'est en cas d'échec.
 *
 * Placé hors de app/Listeners pour ne pas être enregistré deux fois par la
 * découverte automatique des écouteurs.
 */
class AuthEventSubscriber
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if (app()->bound('session.store') && request()->hasSession()) {
            request()->session()->put('auth.authenticated_at', now()->getTimestamp());
        }

        $event->user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        $this->audit->log('auth.login', $event->user, metadata: ['remember' => $event->remember], user: $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        $this->audit->log('auth.failed', metadata: [
            'email' => mb_strtolower((string) ($event->credentials['email'] ?? '')),
        ], user: null);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->log('auth.logout', $event->user, user: $event->user);
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->audit->log('auth.two_factor_enabled', $event->user, user: $event->user);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->audit->log('auth.two_factor_disabled', $event->user);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->audit->log('auth.recovery_codes_generated', $event->user, user: $event->user);
    }

    public function handleRecoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        $this->audit->log('auth.recovery_code_used', $event->user, user: $event->user);
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->audit->log('auth.two_factor_failed', $event->user, user: null);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'handleTwoFactorDisabled',
            RecoveryCodesGenerated::class => 'handleRecoveryCodesGenerated',
            RecoveryCodeReplaced::class => 'handleRecoveryCodeUsed',
            TwoFactorAuthenticationFailed::class => 'handleTwoFactorFailed',
        ];
    }
}
