<?php

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Credential;
use App\Secrets\CredentialSecrets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Actions du gestionnaire de mots de passe sur les accès d'un client.
 *
 * La création et la modification passent par les routes habituelles des
 * accès (mêmes règles, même validation) : seuls la révélation depuis le
 * coffre déverrouillé et le rangement en dossiers sont propres au gestionnaire.
 */
class VaultCredentialController extends Controller
{
    public function __construct(private readonly CredentialSecrets $secrets) {}

    /**
     * Révélation ou copie d'un secret. Le coffre a été déverrouillé par clé
     * d'accès (middleware) ; le droit "secrets", la limitation du débit et
     * la journalisation s'appliquent comme ailleurs.
     */
    public function reveal(Request $request, Client $client, Credential $credential): JsonResponse
    {
        Gate::authorize('reveal', $credential);

        $purpose = $request->validate([
            'purpose' => ['required', Rule::in([CredentialSecrets::PURPOSE_REVEAL, CredentialSecrets::PURPOSE_COPY])],
        ])['purpose'];

        return response()->json([
            'secret' => $this->secrets->reveal($credential, $request->user(), $purpose),
            'expires_in' => 30,
        ]);
    }

    /**
     * Range un ou plusieurs accès dans un dossier (ou à la racine du client).
     */
    public function move(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $data = $request->validate([
            'credential_ids' => ['required', 'array', 'min:1', 'max:500'],
            'credential_ids.*' => ['integer', 'distinct', Rule::exists('credentials', 'id')->where('client_id', $client->id)],
            'folder_id' => ['nullable', 'integer', Rule::exists('vault_folders', 'id')->where('client_id', $client->id)],
        ]);

        $folderId = isset($data['folder_id']) ? (int) $data['folder_id'] : null;
        $moved = 0;

        DB::transaction(function () use ($client, $data, $folderId, &$moved): void {
            $client->credentials()->whereIn('id', $data['credential_ids'])->get()
                ->each(function (Credential $credential) use ($folderId, &$moved): void {
                    if ($credential->folder_id !== $folderId) {
                        $credential->folder_id = $folderId;
                        $credential->save();
                        $moved++;
                    }
                });
        });

        return back()->with('success', $moved === 1 ? '1 accès déplacé.' : "{$moved} accès déplacés.");
    }
}
