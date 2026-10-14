<?php

namespace App\Policies;

use App\Enums\ClientPermission;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un client inaccessible répond comme s'il n'existait pas (404) : aucune
 * information sur son existence n'est révélée.
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Client $client): Response
    {
        return $user->hasClientPermission($client, ClientPermission::View)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->canCreateClients();
    }

    public function update(User $user, Client $client): Response
    {
        return $this->contribute($user, $client);
    }

    /**
     * Création et modification des ressources du client.
     */
    public function contribute(User $user, Client $client): Response
    {
        return $this->check($user, $client, ClientPermission::Edit, requireActive: true);
    }

    public function archive(User $user, Client $client): Response
    {
        return $this->check($user, $client, ClientPermission::Manage, requireActive: true);
    }

    public function restore(User $user, Client $client): Response
    {
        $response = $this->check($user, $client, ClientPermission::Manage);

        if ($response->denied()) {
            return $response;
        }

        return $client->isArchived() ? Response::allow() : Response::deny('Ce client n\'est pas archivé.');
    }

    /**
     * Suppression définitive : administrateur uniquement, client archivé au préalable.
     */
    public function delete(User $user, Client $client): Response
    {
        if (! $user->hasClientPermission($client, ClientPermission::View)) {
            return Response::denyAsNotFound();
        }

        if (! $user->isAdmin()) {
            return Response::deny('Seul un administrateur peut supprimer définitivement un client.');
        }

        return $client->isArchived()
            ? Response::allow()
            : Response::deny('Archivez le client avant de le supprimer définitivement.');
    }

    public function export(User $user, Client $client): Response
    {
        return $this->check($user, $client, ClientPermission::Export);
    }

    public function viewSecrets(User $user, Client $client): Response
    {
        return $this->check($user, $client, ClientPermission::Secrets);
    }

    public function manage(User $user, Client $client): Response
    {
        return $this->check($user, $client, ClientPermission::Manage);
    }

    private function check(User $user, Client $client, ClientPermission $permission, bool $requireActive = false): Response
    {
        if (! $user->hasClientPermission($client, ClientPermission::View)) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasClientPermission($client, $permission)) {
            return Response::deny('Vous n\'avez pas le droit « '.mb_strtolower($permission->label()).' » sur ce client.');
        }

        if ($requireActive && $client->isArchived()) {
            return Response::deny('Ce client est archivé : restaurez-le pour le modifier.');
        }

        return Response::allow();
    }
}
