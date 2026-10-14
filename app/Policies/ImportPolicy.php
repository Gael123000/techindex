<?php

namespace App\Policies;

use App\Enums\ClientPermission;
use App\Enums\ImportType;
use App\Models\Import;
use App\Models\User;

/**
 * Un import n'est visible que par son auteur (ou un administrateur), et ses
 * droits sont revérifiés au moment de l'exécution.
 */
class ImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->canCreateClients() || $user->clientIdsWith(ClientPermission::Edit) !== []);
    }

    public function view(User $user, Import $import): bool
    {
        return $user->is_active && ($user->isAdmin() || $import->user_id === $user->id);
    }

    public function execute(User $user, Import $import): bool
    {
        if (! $this->view($user, $import)) {
            return false;
        }

        return match ($import->type) {
            ImportType::Clients => $user->canCreateClients(),
            ImportType::Equipment => $import->client_id !== null
                && $user->hasClientPermission($import->client_id, ClientPermission::Edit),
        };
    }
}
