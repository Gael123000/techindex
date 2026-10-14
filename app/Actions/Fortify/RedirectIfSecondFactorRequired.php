<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Après un mot de passe correct, exige le second facteur si l'utilisateur en
 * a configuré un : code TOTP confirmé et/ou clé d'accès. Sans second facteur,
 * la connexion se poursuit (l'activation reste imposée ensuite par rôle).
 */
class RedirectIfSecondFactorRequired extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && $user->hasSecondFactor()) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }
}
