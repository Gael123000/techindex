<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fournisseur d'utilisateurs qui ignore les comptes désactivés.
 *
 * Toutes les recherches (identifiants de connexion, session, cookie
 * "se souvenir de moi") passent par newModelQuery() : un compte désactivé
 * ne peut donc ni se connecter, ni conserver une session existante. Le
 * message d'échec reste identique à celui d'un mauvais mot de passe.
 */
class ActiveUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->where('is_active', true);
    }
}
