<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\Passkey;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

class ProfileController extends Controller
{
    public function __construct(private readonly UserSessions $sessions) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $status = $request->session()->get('status');
        $recentlyConfirmed = RequireRecentPasswordConfirmation::isConfirmed($request, 'default');
        $pendingSetup = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        // Le QR code et la clé de configuration ne sont transmis que pendant
        // l'activation, après confirmation récente du mot de passe.
        $setup = $pendingSetup && $recentlyConfirmed ? [
            'qr_svg' => $user->twoFactorQrCodeSvg(),
            'secret_key' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        ] : null;

        // Les codes de récupération ne sont affichés qu'une fois, juste après
        // leur génération.
        $showRecoveryCodes = $recentlyConfirmed
            && $user->two_factor_recovery_codes !== null
            && in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true);

        return Inertia::render('Profile/Show', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'role_label' => $user->role->label(),
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'must_change_password' => $user->must_change_password,
            ],
            'twoFactor' => [
                'enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'pending' => $pendingSetup,
                'required' => $user->requiresTwoFactor(),
                'setup' => $setup,
                'recovery_codes' => $showRecoveryCodes ? $user->recoveryCodes() : null,
            ],
            // Clés d'accès : nom, type d'authentificateur et dates, jamais la clé publique.
            'passkeys' => $user->passkeys()->latest()->get()->map(fn (Passkey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ]),
            'sessions' => $this->sessions->list($user, $request->session()->getId()),
            'status' => $status,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update($validated);

        return back()->with('success', 'Profil mis à jour.');
    }

    public function destroyOtherSessions(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password:web'],
        ], [
            'password.current_password' => 'Le mot de passe est incorrect.',
        ]);

        $count = $this->sessions->revokeOthers($request->user(), $request->session()->getId());
        $audit->log('auth.sessions_revoked', $request->user(), metadata: ['sessions' => $count]);

        return back()->with('success', $count > 0 ? "{$count} autre(s) session(s) fermée(s)." : 'Aucune autre session ouverte.');
    }
}
