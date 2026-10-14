<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FileFolder;
use App\Services\Attachments\FileExplorer;
use App\Services\Folders\FolderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Dossiers de l'explorateur de fichiers d'un client.
 */
class FileFolderController extends Controller
{
    public function __construct(
        private readonly FolderManager $folders,
        private readonly FileExplorer $explorer,
    ) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $data = $request->validate([
            'name' => FolderManager::nameRules(),
            'parent_id' => ['nullable', 'integer', Rule::exists('file_folders', 'id')->where('client_id', $client->id)],
        ], FolderManager::messages());

        $parent = isset($data['parent_id']) ? FileFolder::query()->find($data['parent_id']) : null;
        $folder = $this->folders->create(FileFolder::class, $client, $data['name'], $parent);

        return back()->with('success', 'Dossier « '.$folder->name.' » créé.');
    }

    public function update(Request $request, Client $client, FileFolder $fileFolder): RedirectResponse
    {
        Gate::authorize('update', $fileFolder);

        $data = $request->validate([
            'name' => FolderManager::nameRules(),
            'parent_id' => ['nullable', 'integer', Rule::exists('file_folders', 'id')->where('client_id', $client->id)],
        ], FolderManager::messages());

        $parent = isset($data['parent_id']) ? FileFolder::query()->find($data['parent_id']) : null;
        $this->folders->update($fileFolder, $data['name'], $parent);

        return back()->with('success', 'Dossier mis à jour.');
    }

    /**
     * Contenu qui serait touché par la suppression (affiché avant confirmation).
     */
    public function summary(Client $client, FileFolder $fileFolder): JsonResponse
    {
        Gate::authorize('view', $fileFolder);

        return response()->json($this->explorer->summary($fileFolder));
    }

    /**
     * Supprimer un dossier qui contient des fichiers déposés dans l'explorateur
     * exige le droit de gestion du client ; un dossier vide, ou ne contenant
     * que des fichiers liés à des fiches, le droit de modification.
     */
    public function destroy(Client $client, FileFolder $fileFolder): RedirectResponse
    {
        $summary = $this->explorer->summary($fileFolder);

        Gate::authorize($summary['own'] > 0 ? 'delete' : 'update', $fileFolder);

        $parentId = $fileFolder->parent_id;
        $result = $this->explorer->deleteFolder($fileFolder);

        $message = 'Dossier « '.$fileFolder->name.' » supprimé';
        $message .= $result['deleted'] > 0 ? ', '.$result['deleted'].' fichier(s) supprimé(s)' : '';
        $message .= $result['restored'] > 0 ? ', '.$result['restored'].' fichier(s) lié(s) à une fiche replacé(s) à la racine du client' : '';

        return redirect()
            ->route('files.clients.show', $parentId === null ? [$client] : [$client, 'folder' => $parentId])
            ->with('success', $message.'.');
    }
}
