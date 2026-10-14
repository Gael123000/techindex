<?php

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Credential;
use App\Models\VaultFolder;
use App\Services\Folders\FolderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Dossiers libres du gestionnaire de mots de passe, propres à chaque client.
 *
 * Supprimer un dossier ne supprime jamais d'accès : son contenu (accès et
 * sous-dossiers) remonte dans le dossier parent.
 */
class VaultFolderController extends Controller
{
    public function __construct(private readonly FolderManager $folders) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $data = $request->validate([
            'name' => FolderManager::nameRules(),
            'parent_id' => ['nullable', 'integer', Rule::exists('vault_folders', 'id')->where('client_id', $client->id)],
        ], FolderManager::messages());

        $parent = isset($data['parent_id']) ? VaultFolder::query()->find($data['parent_id']) : null;
        $folder = $this->folders->create(VaultFolder::class, $client, $data['name'], $parent);

        return redirect()
            ->route('vault.clients.show', [$client, 'folder' => $folder->id])
            ->with('success', 'Dossier « '.$folder->name.' » créé.');
    }

    public function update(Request $request, Client $client, VaultFolder $vaultFolder): RedirectResponse
    {
        Gate::authorize('update', $vaultFolder);

        $data = $request->validate([
            'name' => FolderManager::nameRules(),
            'parent_id' => ['nullable', 'integer', Rule::exists('vault_folders', 'id')->where('client_id', $client->id)],
        ], FolderManager::messages());

        $parent = isset($data['parent_id']) ? VaultFolder::query()->find($data['parent_id']) : null;
        $this->folders->update($vaultFolder, $data['name'], $parent);

        return back()->with('success', 'Dossier mis à jour.');
    }

    public function destroy(Client $client, VaultFolder $vaultFolder): RedirectResponse
    {
        Gate::authorize('update', $vaultFolder);

        $parentId = $vaultFolder->parent_id;

        DB::transaction(function () use ($vaultFolder, $parentId): void {
            $tree = VaultFolder::treeFor($vaultFolder->client_id);

            // Les sous-dossiers remontent d'un niveau, renommés en cas de doublon.
            foreach ($vaultFolder->children()->get() as $child) {
                $child->forceFill([
                    'parent_id' => $parentId,
                    'name' => $this->folders->availableName($tree, $parentId, $child->name, $child->id),
                ])->save();
                $tree->put($child->id, $child);
            }

            // Chaque accès est enregistré individuellement : l'historique
            // conserve son changement de dossier.
            $vaultFolder->credentials()->get()->each(function (Credential $credential) use ($parentId): void {
                $credential->folder_id = $parentId;
                $credential->save();
            });

            $vaultFolder->delete();
        });

        return redirect()
            ->route('vault.clients.show', $parentId === null ? [$client] : [$client, 'folder' => $parentId])
            ->with('success', 'Dossier « '.$vaultFolder->name.' » supprimé : son contenu a été déplacé dans le dossier parent.');
    }
}
