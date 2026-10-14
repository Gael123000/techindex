<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Gestion des comptes (administrateurs uniquement). Pas d'inscription
 * publique : seuls les administrateurs créent les comptes.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $users = User::query()
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->withCount(['assignments', 'passkeys'])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'is_active' => $user->is_active,
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'passkeys_count' => $user->passkeys_count,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'assignments_count' => $user->assignments_count,
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => ['q' => $filters['q'] ?? '', 'role' => $filters['role'] ?? null, 'status' => $filters['status'] ?? null],
            'roles' => UserRole::options(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Users/Form', [
            'user' => null,
            'roles' => $this->roles(),
            'mailConfigured' => $this->mailConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'send_invitation' => ['boolean'],
            'password' => ['nullable', 'required_unless:send_invitation,true', 'string', Password::defaults(), 'confirmed'],
        ]);

        $invite = (bool) ($data['send_invitation'] ?? false);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            // Avec une invitation, le mot de passe initial est aléatoire et
            // inconnu de tous : l'utilisateur le définit via le lien reçu.
            'password' => $invite ? Str::password(40) : $data['password'],
            'role' => UserRole::from($data['role']),
            'is_active' => true,
            'must_change_password' => ! $invite,
        ]);

        if ($invite) {
            PasswordBroker::broker()->sendResetLink(['email' => $user->email]);
            $this->audit->log('user.reset_link_sent', $user);
        }

        return redirect()->route('admin.users.edit', $user)->with('success', $invite
            ? 'Compte créé : une invitation à définir le mot de passe a été envoyée.'
            : 'Compte créé avec un mot de passe temporaire, à changer à la première connexion. Attribuez maintenant ses clients.');
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        $assignments = $user->assignments()->get()->keyBy('client_id');

        return Inertia::render('Admin/Users/Form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
                'is_self' => $user->is($request->user()),
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'passkeys_count' => $user->passkeys()->count(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
                'must_change_password' => $user->must_change_password,
                'sessions' => $this->sessions->list($user, null)->count(),
            ],
            'roles' => $this->roles(),
            'mailConfigured' => $this->mailConfigured(),
            'access' => Client::query()->orderBy('name')->get(['id', 'name', 'reference', 'status'])
                ->map(fn (Client $client): array => [
                    'client_id' => $client->id,
                    'name' => $client->name,
                    'reference' => $client->reference,
                    'archived' => $client->status->value === 'archived',
                    'enabled' => $assignments->has($client->id),
                    'can_edit' => (bool) $assignments->get($client->id)?->can_edit,
                    'can_export' => (bool) $assignments->get($client->id)?->can_export,
                    'can_view_secrets' => (bool) $assignments->get($client->id)?->can_view_secrets,
                    'can_manage' => (bool) $assignments->get($client->id)?->can_manage,
                ]),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $role = UserRole::from($data['role']);
        Gate::authorize('changeRole', [$user, $role]);

        $user->fill(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'role' => $role])->save();

        return back()->with('success', 'Compte mis à jour.');
    }

    public function deactivate(User $user): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
            $revoked = $this->sessions->revokeAll($user);
            $this->audit->log('auth.sessions_revoked', $user, metadata: ['sessions' => $revoked, 'motif' => 'désactivation']);
        });

        return back()->with('success', 'Compte désactivé : ses sessions ont été fermées.');
    }

    public function reactivate(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $user->forceFill(['is_active' => true, 'deactivated_at' => null])->save();

        return back()->with('success', 'Compte réactivé.');
    }

    public function revokeSessions(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $revoked = $this->sessions->revokeAll($user);
        $this->audit->log('auth.sessions_revoked', $user, metadata: ['sessions' => $revoked]);

        return back()->with('success', "{$revoked} session(s) fermée(s).");
    }

    public function resetTwoFactor(User $user, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        Gate::authorize('update', $user);

        $disable($user);

        // Les clés d'accès sont aussi un second facteur : un appareil perdu ou
        // volé ne doit plus permettre ni la connexion, ni l'ouverture du coffre.
        $removed = $user->passkeys()->count();
        $user->passkeys()->delete();

        if ($removed > 0) {
            $this->audit->log('auth.passkeys_reset', $user, metadata: ['clés supprimées' => $removed]);
        }

        return back()->with('success', 'Double authentification et clés d\'accès réinitialisées : l\'utilisateur devra les reconfigurer.');
    }

    public function sendResetLink(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        abort_unless($user->is_active, 409, 'Le compte est désactivé.');

        PasswordBroker::broker()->sendResetLink(['email' => $user->email]);
        $this->audit->log('user.reset_link_sent', $user);

        return back()->with('success', 'Lien de réinitialisation envoyé à '.$user->email.'.');
    }

    /**
     * @return list<array{value: string, label: string, description: string, defaults: array<string, bool>}>
     */
    private function roles(): array
    {
        return array_map(fn (UserRole $role): array => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
            'defaults' => $role->defaultClientPermissions(),
        ], UserRole::cases());
    }

    private function mailConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }
}
