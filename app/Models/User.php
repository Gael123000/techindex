<?php

namespace App\Models;

use App\Enums\ClientPermission;
use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Collaborateur du prestataire. Le mot de passe de connexion est haché
 * (cast "hashed") : il n'est jamais réversible, contrairement aux secrets clients.
 *
 * Second facteur : code TOTP et/ou clé d'accès (WebAuthn). Les clés d'accès
 * déverrouillent aussi le gestionnaire de mots de passe.
 *
 * @property UserRole $role
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Valeurs initiales des colonnes facultatives : une instance neuve est
     * complète, comme un enregistrement relu en base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'must_change_password' => false,
        'email_verified_at' => null,
        'password_changed_at' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'last_login_at' => null,
        'last_login_ip' => null,
        'deactivated_at' => null,
    ];

    /**
     * Affectations chargées une seule fois par requête.
     *
     * @var Collection<int, ClientAssignment>|null
     */
    protected ?Collection $assignmentCache = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ClientAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClientAssignment::class);
    }

    /**
     * @return BelongsToMany<Client, $this>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)
            ->using(ClientAssignment::class)
            ->withPivot(['id', 'can_edit', 'can_export', 'can_view_secrets', 'can_manage'])
            ->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canCreateClients(): bool
    {
        return $this->is_active && $this->role->canCreateClients();
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this->role->value, config('techindex.security.two_factor_required_roles', []), true);
    }

    /**
     * Second facteur configuré : code TOTP confirmé ou au moins une clé d'accès.
     */
    public function hasSecondFactor(): bool
    {
        return $this->hasEnabledTwoFactorAuthentication() || $this->hasPasskeysEnabled();
    }

    /**
     * Indique si l'utilisateur possède un droit donné sur un client.
     * Un administrateur possède tous les droits sur tous les clients.
     */
    public function hasClientPermission(Client|int $client, ClientPermission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $clientId = $client instanceof Client ? $client->getKey() : $client;
        $assignment = $this->clientAssignments()->get($clientId);

        if ($assignment === null) {
            return false;
        }

        $column = $permission->column();

        return $column === null || (bool) $assignment->getAttribute($column);
    }

    /**
     * Identifiants des clients pour lesquels l'utilisateur possède le droit
     * demandé. Retourne null lorsqu'aucune restriction ne s'applique (admin).
     *
     * @return list<int>|null
     */
    public function clientIdsWith(ClientPermission $permission = ClientPermission::View): ?array
    {
        if (! $this->is_active) {
            return [];
        }

        if ($this->isAdmin()) {
            return null;
        }

        $column = $permission->column();

        return $this->clientAssignments()
            ->filter(fn (ClientAssignment $assignment): bool => $column === null || (bool) $assignment->getAttribute($column))
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Droits de l'utilisateur sur un client, pour l'interface.
     *
     * @return array<string, bool>
     */
    public function permissionsFor(Client $client): array
    {
        $permissions = [];

        foreach (ClientPermission::cases() as $permission) {
            $permissions[$permission->value] = $this->hasClientPermission($client, $permission);
        }

        return $permissions;
    }

    /**
     * @return Collection<int, ClientAssignment>
     */
    protected function clientAssignments(): Collection
    {
        return $this->assignmentCache ??= $this->assignments()->get()->keyBy('client_id');
    }

    public function flushAccessCache(): void
    {
        $this->assignmentCache = null;
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function auditLabel(): string
    {
        return $this->name.' <'.$this->email.'>';
    }

    protected function auditExcluded(): array
    {
        return ['last_login_at', 'last_login_ip', 'password_changed_at', 'two_factor_confirmed_at', 'deactivated_at', 'email_verified_at'];
    }

    protected function resolveAuditUpdateAction(): string
    {
        if ($this->wasChanged('is_active')) {
            return $this->is_active ? 'reactivated' : 'deactivated';
        }

        if ($this->wasChanged('role')) {
            return 'role_changed';
        }

        return 'updated';
    }
}
