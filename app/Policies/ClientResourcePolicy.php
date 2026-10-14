<?php

namespace App\Policies;

use App\Enums\ClientPermission;
use App\Enums\RecordStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Politique commune aux ressources rattachées à un client (sites, contacts,
 * équipements, services, licences, certificats, documents, pièces jointes...).
 *
 * - Lecture : affectation au client, sinon 404.
 * - Modification / archivage : droit "modification", client non archivé.
 * - Suppression définitive : droit "gestion", client non archivé.
 */
class ClientResourcePolicy
{
    public function view(User $user, Model $resource): Response
    {
        return $user->hasClientPermission($this->clientId($resource), ClientPermission::View)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Model $resource): Response
    {
        return $this->check($user, $resource, ClientPermission::Edit);
    }

    public function archive(User $user, Model $resource): Response
    {
        return $this->check($user, $resource, ClientPermission::Edit);
    }

    public function delete(User $user, Model $resource): Response
    {
        return $this->check($user, $resource, ClientPermission::Manage);
    }

    protected function check(User $user, Model $resource, ClientPermission $permission): Response
    {
        $clientId = $this->clientId($resource);

        if (! $user->hasClientPermission($clientId, ClientPermission::View)) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasClientPermission($clientId, $permission)) {
            return Response::deny('Vous n\'avez pas le droit « '.mb_strtolower($permission->label()).' » sur ce client.');
        }

        // Valeur brute (sans cast en énumération) pour une comparaison fiable.
        if (Client::query()->whereKey($clientId)->toBase()->value('status') === RecordStatus::Archived->value) {
            return Response::deny('Ce client est archivé : restaurez-le pour modifier ses données.');
        }

        return Response::allow();
    }

    protected function clientId(Model $resource): int
    {
        return (int) $resource->getAttribute('client_id');
    }
}
