<?php

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Credential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Actions du gestionnaire de mots de passe sur les accès d'un client.
 *
 * La création, la modification et la révélation passent par les routes
 * habituelles des accès (mêmes règles, même verrou par clé d'accès) : seul
 * le rangement en dossiers est propre au gestionnaire.
 */
class VaultCredentialController extends Controller
{
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
