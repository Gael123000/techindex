<?php

namespace App\Http\Controllers\Auth;

use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Saisie d'un code TOTP ou d'un code de récupération. Un utilisateur dont le
 * seul second facteur est une clé d'accès n'a pas de secret TOTP : un code
 * saisi est alors refusé proprement au lieu de provoquer une erreur.
 */
class TwoFactorChallengeController extends TwoFactorAuthenticatedSessionController
{
    /**
     * @return mixed
     */
    public function store(TwoFactorLoginRequest $request)
    {
        $user = $request->challengedUser();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            event(new TwoFactorAuthenticationFailed($user));

            return app(FailedTwoFactorLoginResponse::class)->toResponse($request);
        }

        return parent::store($request);
    }
}
