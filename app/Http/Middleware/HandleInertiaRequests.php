<?php

namespace App\Http\Middleware;

use App\Models\Import;
use App\Services\Updates\UpdateManager;
use App\Services\Vault\VaultLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Middleware;

/**
 * Données partagées avec toutes les pages. Aucune donnée sensible n'y figure :
 * les secrets ne transitent que par l'appel explicite de révélation.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Statuts renvoyés par Fortify, traduits en messages.
     *
     * @var array<string, string>
     */
    private const STATUS_MESSAGES = [
        'password-updated' => 'Mot de passe modifié. Vos autres sessions ont été fermées.',
        'two-factor-authentication-enabled' => 'Scannez le QR code puis saisissez le code affiché pour terminer l\'activation.',
        'two-factor-authentication-confirmed' => 'Double authentification activée. Conservez vos codes de récupération.',
        'two-factor-authentication-disabled' => 'Double authentification désactivée.',
        'recovery-codes-generated' => 'Nouveaux codes de récupération générés : les anciens ne sont plus valables.',
    ];

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'tagline' => config('techindex.tagline'),
                'timezone' => config('app.display_timezone'),
            ],
            'auth' => fn (): ?array => $user === null ? null : [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                    'has_passkeys' => $user->hasPasskeysEnabled(),
                    'must_change_password' => $user->must_change_password,
                ],
                'can' => [
                    'manage_users' => Gate::forUser($user)->allows('manage-users'),
                    'manage_settings' => Gate::forUser($user)->allows('manage-settings'),
                    'manage_updates' => Gate::forUser($user)->allows('manage-updates'),
                    'create_clients' => Gate::forUser($user)->allows('create-clients'),
                    'view_audit' => Gate::forUser($user)->allows('view-audit-log'),
                    'import' => Gate::forUser($user)->allows('viewAny', Import::class),
                ],
            ],
            // Temps restant avant le reverrouillage des mots de passe (0 : verrouillés).
            'vault' => fn (): ?array => $user === null ? null : ['expires_in' => VaultLock::expiresIn($request)],
            'flash' => function () use ($request): array {
                $success = $request->session()->get('success') ?? self::STATUS_MESSAGES[$request->session()->get('status')] ?? null;
                $error = $request->session()->get('error');

                return [
                    'success' => $success,
                    'error' => $error,
                    'status' => $request->session()->get('status'),
                    // Identifiant propre à chaque message : un message déjà affiché ne
                    // l'est pas de nouveau quand la page se recharge partiellement.
                    'id' => $success !== null || $error !== null ? Str::random(16) : null,
                ];
            },
            // Dernière recherche de mise à jour (sans appel réseau), pour les administrateurs.
            'update_available' => fn (): bool => $user !== null && Gate::forUser($user)->allows('manage-updates')
                && app(UpdateManager::class)->updateAvailable(),
        ];
    }
}
