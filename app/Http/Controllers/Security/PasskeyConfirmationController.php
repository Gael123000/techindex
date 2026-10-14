<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasskeyCeremony;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Confirmation de l'identité par clé d'accès, à la place du mot de passe,
 * avant une action sensible (révélation d'un secret, sécurité du compte).
 * Elle a la même durée de validité qu'une confirmation par mot de passe.
 */
class PasskeyConfirmationController extends Controller
{
    public function __construct(
        private readonly PasskeyCeremony $ceremony,
        private readonly AuditLogger $audit,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasPasskeysEnabled(), 422, 'Aucune clé d\'accès n\'est enregistrée pour votre compte.');

        return response()
            ->json(['options' => $this->ceremony->verificationOptions($request, PasskeyCeremony::CONFIRM, $user)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(PasskeyCeremony::credentialRules());

        try {
            $passkey = $this->ceremony->verify($request, PasskeyCeremony::CONFIRM, $user, $data['credential']);
        } catch (ValidationException $exception) {
            $this->audit->log('auth.passkey_failed', $user, metadata: ['usage' => 'confirmation']);

            throw $exception;
        }

        $request->session()->passwordConfirmed();
        $this->audit->log('auth.passkey_used', $passkey, metadata: ['usage' => 'confirmation']);

        return response()->json(['confirmed' => true])->header('Cache-Control', 'no-store, private');
    }
}
