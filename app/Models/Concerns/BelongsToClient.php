<?php

namespace App\Models\Concerns;

use App\Enums\ClientPermission;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ressource rattachée à un client (colonne client_id).
 *
 * Le scope visibleTo() applique les droits directement dans la requête SQL :
 * il est utilisé par les listes, la recherche, les exports et le tableau de
 * bord afin qu'aucune donnée d'un client inaccessible ne soit chargée.
 */
trait BelongsToClient
{
    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user, ClientPermission $permission = ClientPermission::View): Builder
    {
        $clientIds = $user->clientIdsWith($permission);

        if ($clientIds === null) {
            return $query;
        }

        return $query->whereIn($this->qualifyColumn('client_id'), $clientIds);
    }

    public function auditClientId(): ?int
    {
        return $this->client_id;
    }
}
