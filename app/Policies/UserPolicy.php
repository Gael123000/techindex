<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Gestion des comptes : réservée aux administrateurs, avec des garde-fous
 * empêchant de se retirer soi-même l'accès d'administration.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function deactivate(User $user, User $target): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        if ($user->is($target)) {
            return Response::deny('Vous ne pouvez pas désactiver votre propre compte.');
        }

        return Response::allow();
    }

    public function changeRole(User $user, User $target, UserRole $newRole): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        if ($user->is($target) && $newRole !== UserRole::Admin) {
            return Response::deny('Vous ne pouvez pas retirer votre propre rôle d\'administrateur.');
        }

        return Response::allow();
    }
}
