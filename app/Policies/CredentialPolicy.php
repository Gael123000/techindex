<?php

namespace App\Policies;

use App\Enums\ClientPermission;
use App\Models\Credential;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Les métadonnées d'un accès (libellé, hôte, utilisateur) suivent les règles
 * des ressources. La valeur du secret exige en plus le droit "secrets".
 */
class CredentialPolicy extends ClientResourcePolicy
{
    /**
     * Révéler ou copier le secret.
     */
    public function reveal(User $user, Credential $credential): Response
    {
        if (! $user->hasClientPermission($credential->client_id, ClientPermission::View)) {
            return Response::denyAsNotFound();
        }

        return $user->hasClientPermission($credential->client_id, ClientPermission::Secrets)
            ? Response::allow()
            : Response::deny('Vous n\'avez pas accès aux secrets de ce client.');
    }

    /**
     * Définir, remplacer ou supprimer la valeur du secret.
     */
    public function writeSecret(User $user, Credential $credential): Response
    {
        $response = $this->check($user, $credential, ClientPermission::Edit);

        if ($response->denied()) {
            return $response;
        }

        return $this->reveal($user, $credential);
    }
}
