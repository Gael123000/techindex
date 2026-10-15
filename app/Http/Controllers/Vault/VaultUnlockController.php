<?php

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasskeyCeremony;
use App\Services\Vault\VaultLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Déverrouillage des mots de passe (gestionnaire et fiches), uniquement par
 * clé d'accès avec vérification de l'utilisateur (biométrie ou code PIN) :
 * le mot de passe seul, même confirmé, ne suffit pas. L'ouverture dure
 * VaultLock::DURATION secondes, sans prolongation.
 */
class VaultUnlockController extends Controller
{
    public function __construct(
        private readonly PasskeyCeremony $ceremony,
        private readonly AuditLogger $audit,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasPasskeysEnabled(), 422, 'Ajoutez d\'abord une clé d\'accès depuis votre profil.');

        return response()
            ->json(['options' => $this->ceremony->verificationOptions($request, PasskeyCeremony::VAULT, $user)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(PasskeyCeremony::credentialRules());

        try {
            $passkey = $this->ceremony->verify($request, PasskeyCeremony::VAULT, $user, $data['credential']);
        } catch (ValidationException $exception) {
            $this->audit->log('vault.unlock_failed', $user);

            throw $exception;
        }

        VaultLock::unlock($request);
        $this->audit->log('vault.unlocked', $passkey, metadata: ['clé' => $passkey->name]);

        return response()
            ->json(['unlocked' => true, 'expires_in' => VaultLock::expiresIn($request)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        VaultLock::lock($request);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['locked' => true])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route('vault.index')->with('success', 'Mots de passe verrouillés.');
    }
}
