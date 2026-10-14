<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasskeyCeremony;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Second facteur par clé d'accès, après un mot de passe correct.
 *
 * L'utilisateur est celui dont le mot de passe vient d'être vérifié
 * (session "login.id", posée par Fortify) : seules ses clés sont proposées et
 * acceptées. La clé d'accès n'est jamais un moyen de connexion sans mot de passe.
 */
class PasskeyChallengeController extends Controller
{
    public function __construct(
        private readonly PasskeyCeremony $ceremony,
        private readonly StatefulGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $user = $this->challengedUser($request);

        abort_unless($user->hasPasskeysEnabled(), 422, 'Aucune clé d\'accès n\'est enregistrée pour ce compte.');

        return response()
            ->json(['options' => $this->ceremony->verificationOptions($request, PasskeyCeremony::LOGIN, $user)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->challengedUser($request);
        $data = $request->validate(PasskeyCeremony::credentialRules());

        try {
            $passkey = $this->ceremony->verify($request, PasskeyCeremony::LOGIN, $user, $data['credential']);
        } catch (ValidationException $exception) {
            $this->audit->log('auth.passkey_failed', $user, metadata: ['usage' => 'connexion'], user: null);

            throw $exception;
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        $this->guard->login($user, $remember);
        $request->session()->regenerate();

        $this->audit->log('auth.passkey_used', $passkey, metadata: ['usage' => 'connexion'], user: $user);

        return response()
            ->json(['redirect' => redirect()->intended(Fortify::redirects('login', '/dashboard'))->getTargetUrl()])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Utilisateur en cours de connexion, encore actif.
     */
    private function challengedUser(Request $request): User
    {
        $id = $request->session()->get('login.id');
        $user = $id === null ? null : User::query()->find($id);

        if (! $user instanceof User || ! $user->is_active) {
            throw new HttpResponseException(response()->json([
                'message' => 'La connexion a expiré : saisissez de nouveau votre mot de passe.',
            ], 401));
        }

        return $user;
    }
}
