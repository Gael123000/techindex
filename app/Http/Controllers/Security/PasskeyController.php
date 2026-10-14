<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasskeyCeremony;
use App\Services\Vault\VaultLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clés d'accès de l'utilisateur connecté (profil).
 *
 * L'ajout et la suppression exigent une confirmation récente de l'identité :
 * un tiers profitant d'une session ouverte ne peut pas enregistrer sa propre
 * clé pour ouvrir ensuite le gestionnaire de mots de passe.
 */
class PasskeyController extends Controller
{
    public function __construct(
        private readonly PasskeyCeremony $ceremony,
        private readonly AuditLogger $audit,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()
            ->json(['options' => $this->ceremony->registrationOptions($request, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            ...PasskeyCeremony::credentialRules(),
        ], [], ['name' => 'nom de la clé']);

        $user = $request->user();
        $passkey = $this->ceremony->register($request, $user, trim($data['name']), $data['credential']);

        $this->audit->log('auth.passkey_added', $passkey, metadata: [
            'authentificateur' => $passkey->authenticator,
        ], user: $user);

        $request->session()->flash('success', 'Clé d\'accès « '.$passkey->name.' » ajoutée.');

        return response()->json(['id' => $passkey->id])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, Passkey $passkey): RedirectResponse
    {
        $this->ensureOwnership($request, $passkey);

        $data = $request->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => 'nom de la clé']);
        $passkey->forceFill(['name' => trim($data['name'])])->save();

        return back()->with('success', 'Clé d\'accès renommée.');
    }

    public function destroy(Request $request, Passkey $passkey): RedirectResponse
    {
        $this->ensureOwnership($request, $passkey);

        $passkey->delete();
        $this->audit->log('auth.passkey_removed', $passkey, user: $request->user());

        // Le gestionnaire de mots de passe a pu être ouvert avec cette clé.
        VaultLock::lock($request);

        return back()->with('success', 'Clé d\'accès « '.$passkey->name.' » supprimée.');
    }

    /**
     * La clé d'un autre utilisateur répond comme si elle n'existait pas.
     */
    private function ensureOwnership(Request $request, Passkey $passkey): void
    {
        abort_unless((int) $passkey->user_id === (int) $request->user()->getKey(), 404);
    }
}
