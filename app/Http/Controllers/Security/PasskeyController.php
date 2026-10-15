<?php

namespace App\Http\Controllers\Security;

use App\Enums\PasskeyType;
use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasskeyCeremony;
use App\Services\Vault\VaultLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            ...$this->descriptionRules(),
            ...PasskeyCeremony::credentialRules(),
        ], $this->descriptionMessages(), $this->descriptionAttributes());

        $user = $request->user();
        $type = PasskeyType::from($data['type']);

        $passkey = DB::transaction(function () use ($request, $user, $type, $data): Passkey {
            $passkey = $this->ceremony->register($request, $user, Passkey::nameFor($type, $data['name'] ?? null), $data['credential']);
            $passkey->forceFill(['type' => $type])->save();

            return $passkey;
        });

        $this->audit->log('auth.passkey_added', $passkey, metadata: [
            'type' => $type->shortLabel(),
            'authentificateur' => $passkey->authenticator,
        ], user: $user);

        $request->session()->flash('success', 'Clé d\'accès « '.$passkey->name.' » ajoutée.');

        return response()->json(['id' => $passkey->id])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, Passkey $passkey): RedirectResponse
    {
        $this->ensureOwnership($request, $passkey);

        $data = $request->validate($this->descriptionRules(), $this->descriptionMessages(), $this->descriptionAttributes());
        $type = PasskeyType::from($data['type']);

        $passkey->forceFill(['type' => $type, 'name' => Passkey::nameFor($type, $data['name'] ?? null)])->save();

        return back()->with('success', 'Clé d\'accès modifiée.');
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
     * Type choisi dans la liste ; précision facultative, sauf pour « Autre ».
     *
     * @return array<string, mixed>
     */
    private function descriptionRules(): array
    {
        return [
            'type' => ['required', Rule::enum(PasskeyType::class)],
            'name' => ['nullable', 'string', 'max:100', 'required_if:type,'.PasskeyType::Other->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function descriptionMessages(): array
    {
        return ['name.required_if' => 'Précisez de quelle clé il s\'agit (par exemple « PC du bureau »).'];
    }

    /**
     * @return array<string, string>
     */
    private function descriptionAttributes(): array
    {
        return ['type' => 'type de clé', 'name' => 'précision'];
    }

    /**
     * La clé d'un autre utilisateur répond comme si elle n'existait pas.
     */
    private function ensureOwnership(Request $request, Passkey $passkey): void
    {
        abort_unless((int) $passkey->user_id === (int) $request->user()->getKey(), 404);
    }
}
