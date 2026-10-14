<?php

namespace App\Http\Controllers\Files;

use App\Enums\ClientPermission;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\FileFolder;
use App\Models\User;
use App\Rules\AllowedAttachment;
use App\Services\Attachments\AttachmentStorage;
use App\Services\Attachments\FileExplorer;
use App\Services\Folders\FolderManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Explorateur de fichiers : un dossier racine par client accessible (créé
 * implicitement), des sous-dossiers libres, l'envoi de fichiers ou de
 * dossiers entiers et le rangement des pièces jointes des fiches.
 *
 * Le téléchargement et la suppression d'un fichier passent par le contrôleur
 * des pièces jointes : mêmes contrôles de droits, même journalisation.
 */
class FileExplorerController extends Controller
{
    public function __construct(
        private readonly FileExplorer $explorer,
        private readonly FolderManager $folders,
        private readonly AttachmentStorage $storage,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();
        $term = trim($filters['q'] ?? '');
        $results = [];

        if ($term !== '') {
            $attachments = Attachment::query()
                ->visibleTo($user)
                ->where('original_name', 'like', '%'.addcslashes($term, '\\%_').'%')
                ->with(['client:id,name', 'uploader:id,name'])
                ->orderBy('original_name')
                ->limit(100)
                ->get();

            $trees = FileFolder::query()
                ->whereIn('client_id', $attachments->pluck('client_id')->unique()->all())
                ->get(FileFolder::TREE_COLUMNS)
                ->keyBy('id');

            $results = $this->presentFiles($attachments, $user, $trees);
        }

        return Inertia::render('Files/Index', [
            'clients' => $this->clientList($user),
            'client' => null,
            'search' => ['q' => $term, 'results' => $results],
        ]);
    }

    public function show(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'folder' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $tree = FileFolder::treeFor($client->id);
        $current = isset($filters['folder']) ? ($tree->get((int) $filters['folder']) ?? abort(404)) : null;
        $term = trim($filters['q'] ?? '');
        $canContribute = Gate::allows('contribute', $client);

        $fileCounts = Attachment::query()
            ->where('client_id', $client->id)
            ->groupBy('folder_id')
            ->selectRaw('folder_id, count(*) as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) ($row->folder_id ?? 0) => (int) $row->total]);

        $files = Attachment::query()
            ->where('client_id', $client->id)
            ->when($term !== '',
                fn (Builder $query) => $query->where('original_name', 'like', '%'.addcslashes($term, '\\%_').'%'),
                fn (Builder $query) => $query->where('folder_id', $current?->id),
            )
            ->with('uploader:id,name')
            ->orderBy('original_name')
            ->paginate(200)
            ->withQueryString();

        $files->setCollection(collect($this->presentFiles($files->getCollection(), $user, $tree)));

        return Inertia::render('Files/Index', [
            'clients' => $this->clientList($user),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'reference' => $client->reference,
                'status' => $client->status->value,
                'root_count' => $fileCounts->get(0, 0),
            ],
            'folders' => $tree->values()->map(fn (FileFolder $folder): array => [
                'id' => $folder->id,
                'parent_id' => $folder->parent_id,
                'name' => $folder->name,
                'count' => $fileCounts->get($folder->id, 0),
            ]),
            'current' => $current === null ? null : [
                'id' => $current->id,
                'name' => $current->name,
                'parent_id' => $current->parent_id,
                'path' => array_map(fn (FileFolder $folder): array => ['id' => $folder->id, 'name' => $folder->name], $current->ancestry($tree)),
            ],
            'subfolders' => $term !== '' ? [] : $tree->filter(fn (FileFolder $folder): bool => $folder->parent_id === $current?->id)->values()
                ->map(fn (FileFolder $folder): array => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'files' => $fileCounts->get($folder->id, 0),
                    'folders' => $tree->where('parent_id', $folder->id)->count(),
                ]),
            'files' => $files,
            'filters' => ['q' => $term],
            'upload' => $canContribute ? [
                'url' => route('files.clients.upload', $client),
                'accept' => AllowedAttachment::acceptAttribute(),
                'max_size_kb' => (int) config('techindex.attachments.max_size_kb'),
            ] : null,
            'can' => [
                'contribute' => $canContribute,
                'manage' => $canContribute && $user->hasClientPermission($client, ClientPermission::Manage),
            ],
        ]);
    }

    /**
     * Envoi d'un fichier (réponse JSON). Pour un dossier importé, l'interface
     * envoie chaque fichier avec son chemin relatif ; les sous-dossiers
     * manquants sont créés.
     */
    public function upload(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('contribute', $client);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('techindex.attachments.max_size_kb'), new AllowedAttachment],
            'folder_id' => ['nullable', 'integer', Rule::exists('file_folders', 'id')->where('client_id', $client->id)],
            'relative_path' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $target = isset($data['folder_id']) ? FileFolder::query()->find($data['folder_id']) : null;

        $attachment = DB::transaction(function () use ($request, $client, $data, $target): Attachment {
            $folder = $this->explorer->resolveUploadFolder($client, $target, $data['relative_path'] ?? null);

            return $this->storage->store($request->file('file'), $client, $client, $request->user(), $data['description'] ?? null, $folder);
        });

        return response()->json([
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'folder_id' => $attachment->folder_id,
        ], 201);
    }

    /**
     * Déplace des fichiers et des dossiers vers un dossier (ou la racine).
     */
    public function move(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $data = $request->validate([
            'attachment_ids' => ['array', 'max:500', 'required_without:folder_ids'],
            'attachment_ids.*' => ['integer', 'distinct', Rule::exists('attachments', 'id')->where('client_id', $client->id)],
            'folder_ids' => ['array', 'max:200', 'required_without:attachment_ids'],
            'folder_ids.*' => ['integer', 'distinct', Rule::exists('file_folders', 'id')->where('client_id', $client->id)],
            'target_folder_id' => ['nullable', 'integer', Rule::exists('file_folders', 'id')->where('client_id', $client->id)],
        ]);

        $target = isset($data['target_folder_id']) ? FileFolder::query()->find($data['target_folder_id']) : null;
        $moved = 0;

        DB::transaction(function () use ($client, $data, $target, &$moved): void {
            foreach ($client->fileFolders()->whereIn('id', $data['folder_ids'] ?? [])->get() as $folder) {
                if ($folder->parent_id !== $target?->id) {
                    $this->folders->update($folder, $folder->name, $target);
                    $moved++;
                }
            }

            foreach ($client->attachments()->whereIn('id', $data['attachment_ids'] ?? [])->get() as $attachment) {
                if ($attachment->folder_id !== $target?->id) {
                    $attachment->folder_id = $target?->id;
                    $attachment->save();
                    $moved++;
                }
            }
        });

        return back()->with('success', $moved === 1 ? '1 élément déplacé.' : "{$moved} éléments déplacés.");
    }

    /**
     * Renomme un fichier (l'extension est conservée) et modifie sa description.
     */
    public function update(Request $request, Client $client, Attachment $attachment): RedirectResponse
    {
        Gate::authorize('update', $attachment);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'regex:'.FolderManager::NAME_PATTERN],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['name.regex' => 'Le nom ne peut pas contenir les caractères / \\ : * ? " < > |.']);

        $attachment->description = $data['description'] ?? null;
        $this->storage->rename($attachment, trim($data['name']));

        return back()->with('success', 'Fichier mis à jour.');
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     * @param  Collection<int, FileFolder>  $folders  dossiers des clients concernés, indexés par id
     * @return list<array<string, mixed>>
     */
    private function presentFiles(Collection $attachments, User $user, Collection $folders): array
    {
        $links = $this->explorer->links($attachments);
        $rights = [];

        return $attachments->map(function (Attachment $attachment) use ($links, $user, $folders, &$rights): array {
            $rights[$attachment->client_id] ??= [
                'edit' => $user->can('contribute', Client::query()->find($attachment->client_id)),
                'manage' => $user->hasClientPermission($attachment->client_id, ClientPermission::Manage),
            ];
            $canEdit = $rights[$attachment->client_id]['edit'];
            $folder = $attachment->folder_id === null ? null : $folders->get($attachment->folder_id);

            return [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'base_name' => pathinfo($attachment->original_name, PATHINFO_FILENAME),
                'extension' => $attachment->extension,
                'description' => $attachment->description,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'created_at' => $attachment->created_at?->toIso8601String(),
                'updated_at' => $attachment->updated_at?->toIso8601String(),
                'uploader' => $attachment->uploader?->only(['id', 'name']),
                'client' => $attachment->relationLoaded('client') ? $attachment->client?->only(['id', 'name']) : null,
                'folder' => $folder === null ? null : ['id' => $folder->id, 'path' => $folder->pathLabel($folders)],
                'linked' => $links[$attachment->id] ?? null,
                'download_url' => route('clients.attachments.show', [$attachment->client_id, $attachment->id]),
                'preview_url' => $attachment->isInlineViewable() ? route('clients.attachments.show', [$attachment->client_id, $attachment->id, 'inline' => 1]) : null,
                'can_rename' => $canEdit,
                'can_delete' => $canEdit && ($rights[$attachment->client_id]['manage'] || $attachment->uploaded_by === $user->id),
            ];
        })->values()->all();
    }

    /**
     * Clients accessibles : chacun est automatiquement un dossier racine.
     *
     * @return list<array<string, mixed>>
     */
    private function clientList(User $user): array
    {
        return Client::query()
            ->visibleTo($user)
            ->withCount('attachments')
            ->orderBy('name')
            ->get(['id', 'name', 'reference', 'status'])
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'reference' => $client->reference,
                'archived' => $client->isArchived(),
                'count' => $client->attachments_count,
            ])
            ->all();
    }
}
