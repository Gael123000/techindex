<?php

namespace App\Models;

use App\Enums\ClientPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée du journal d'audit. Le journal est en ajout seul : l'application
 * ne modifie ni ne supprime d'entrée (hors purge planifiée facultative).
 *
 * @property array<string, array{old: mixed, new: mixed}>|null $changes
 * @property array<string, mixed>|null $metadata
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Entrées visibles par un utilisateur : tout pour un administrateur ;
     * sinon uniquement les événements des clients où il a le droit demandé.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user, ClientPermission $permission = ClientPermission::View): Builder
    {
        $clientIds = $user->clientIdsWith($permission);

        return $clientIds === null ? $query : $query->whereIn('client_id', $clientIds);
    }
}
