<?php

namespace App\Http\Controllers\Vault;

use App\Enums\AccessType;
use App\Enums\ClientPermission;
use App\Enums\EquipmentStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Credential;
use App\Models\Equipment;
use App\Models\Service;
use App\Models\User;
use App\Models\VaultFolder;
use App\Services\Vault\VaultLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestionnaire de mots de passe : vue des accès de tous les clients autorisés,
 * rangés automatiquement par client, par équipement et par service, et dans
 * des dossiers libres.
 *
 * Les données affichées proviennent de la table des accès, comme les fiches
 * équipement et service : aucune copie, donc aucune désynchronisation
 * possible. Les pages ne contiennent que des métadonnées ; une valeur de
 * secret n'est transmise qu'en réponse à une demande explicite (révélation).
 */
class VaultController extends Controller
{
    use InteractsWithClient;

    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();
        $term = trim($filters['q'] ?? '');

        $results = $term === '' ? [] : Credential::query()
            ->visibleTo($user)
            ->where(fn (Builder $query) => $this->matching($query, $term))
            ->with(['client:id,name', 'equipment:id,name', 'service:id,name', 'internetLink:id,name', 'folder:id,name'])
            ->orderBy('label')
            ->limit(100)
            ->get()
            ->map(fn (Credential $credential): array => [
                'id' => $credential->id,
                'label' => $credential->label,
                'username' => $credential->username,
                'access_type_label' => $credential->access_type->label(),
                'client' => $credential->client->only(['id', 'name']),
                'equipment' => $credential->equipment?->only(['id', 'name']),
                'service' => $credential->service?->only(['id', 'name']),
                'folder' => $credential->folder?->only(['id', 'name']),
                'url' => route('vault.clients.show', [$credential->client_id, 'credential' => $credential->id]),
            ])
            ->all();

        return Inertia::render('Vault/Index', [
            'clients' => $this->clientList($user),
            'client' => null,
            'search' => ['q' => $term, 'results' => $results],
            'lock' => $this->lockState($request),
        ]);
    }

    public function show(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'integer'],
            'equipment' => ['nullable', 'integer'],
            'service' => ['nullable', 'integer'],
            'view' => ['nullable', Rule::in(['general', 'unfiled'])],
            'credential' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $tree = VaultFolder::treeFor($client->id);
        $equipment = $client->equipment()->with('site:id,name')->orderBy('name')->get(['id', 'client_id', 'site_id', 'name', 'hostname', 'status']);
        $services = $client->services()->orderBy('name')->get(['id', 'client_id', 'name', 'category', 'status']);
        $counts = $this->counts($client);

        [$selection, $constrain] = $this->selection($filters, $tree, $equipment, $services, $client);
        $term = trim($filters['q'] ?? '');

        $credentials = $client->credentials()
            ->tap($constrain)
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $this->matching($query, $term)))
            ->with(['equipment:id,name', 'service:id,name', 'internetLink:id,name', 'folder:id,name'])
            ->orderBy('label')
            ->paginate(100)
            ->withQueryString();

        $credentials->setCollection($this->presentCredentials($credentials->getCollection(), $client, $user, 'vault.clients.credentials.reveal'));

        $currentFolderId = $selection['type'] === 'folder' ? $selection['id'] : null;
        $showSubfolders = in_array($selection['type'], ['all', 'folder'], true) && $term === '';
        $canContribute = Gate::allows('contribute', $client);

        return Inertia::render('Vault/Index', [
            'clients' => $this->clientList($user),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'reference' => $client->reference,
                'status' => $client->status->value,
            ],
            'tree' => [
                'folders' => $tree->values()->map(fn (VaultFolder $folder): array => [
                    'id' => $folder->id,
                    'parent_id' => $folder->parent_id,
                    'name' => $folder->name,
                    'count' => $counts['folders'][$folder->id] ?? 0,
                ]),
                'equipment' => $equipment
                    ->filter(fn (Equipment $item): bool => $item->status !== EquipmentStatus::Archived || isset($counts['equipment'][$item->id]))
                    ->values()
                    ->map(fn (Equipment $item): array => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'hostname' => $item->hostname,
                        'site' => $item->site?->name,
                        'count' => $counts['equipment'][$item->id] ?? 0,
                    ]),
                'services' => $services
                    ->filter(fn (Service $item): bool => $item->status !== ServiceStatus::Archived || isset($counts['services'][$item->id]))
                    ->values()
                    ->map(fn (Service $item): array => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'category_label' => $item->category->label(),
                        'count' => $counts['services'][$item->id] ?? 0,
                    ]),
                'counts' => $counts['totals'],
            ],
            'selection' => $selection,
            'subfolders' => $showSubfolders
                ? $tree->filter(fn (VaultFolder $folder): bool => $folder->parent_id === $currentFolderId)->values()
                    ->map(fn (VaultFolder $folder): array => ['id' => $folder->id, 'name' => $folder->name, 'count' => $counts['folders'][$folder->id] ?? 0])
                : [],
            'credentials' => $credentials,
            'filters' => ['q' => $term],
            'highlight' => $filters['credential'] ?? null,
            'options' => [
                'types' => AccessType::options(),
                'equipment' => $this->equipmentOptions($client),
                'services' => $this->serviceOptions($client),
                'folders' => $tree->values()
                    ->map(fn (VaultFolder $folder): array => ['value' => $folder->id, 'label' => $folder->pathLabel($tree)])
                    ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values(),
            ],
            'can' => [
                'create' => $canContribute,
                'manage_folders' => $canContribute,
                'write_secrets' => $canContribute && $user->hasClientPermission($client, ClientPermission::Secrets),
                'reveal' => $user->hasClientPermission($client, ClientPermission::Secrets),
            ],
            'lock' => $this->lockState($request),
        ]);
    }

    /**
     * Élément sélectionné dans l'arborescence et contrainte correspondante.
     * Un identifiant d'un autre client répond 404.
     *
     * @param  array<string, mixed>  $filters
     * @param  \Illuminate\Database\Eloquent\Collection<int, VaultFolder>  $tree
     * @param  \Illuminate\Database\Eloquent\Collection<int, Equipment>  $equipment
     * @param  \Illuminate\Database\Eloquent\Collection<int, Service>  $services
     * @return array{0: array<string, mixed>, 1: callable(Builder): void}
     */
    private function selection(array $filters, Collection $tree, Collection $equipment, Collection $services, Client $client): array
    {
        if (isset($filters['folder'])) {
            $folder = $tree->get((int) $filters['folder']) ?? abort(404);

            return [
                [
                    'type' => 'folder',
                    'id' => $folder->id,
                    'label' => $folder->name,
                    'path' => array_map(fn (VaultFolder $item): array => ['id' => $item->id, 'name' => $item->name], $folder->ancestry($tree)),
                    'parent_id' => $folder->parent_id,
                ],
                fn (Builder $query) => $query->where('folder_id', $folder->id),
            ];
        }

        if (isset($filters['equipment'])) {
            $item = $equipment->firstWhere('id', (int) $filters['equipment']) ?? abort(404);

            return [
                ['type' => 'equipment', 'id' => $item->id, 'label' => $item->name, 'url' => route('clients.equipment.show', [$client, $item])],
                fn (Builder $query) => $query->where('equipment_id', $item->id),
            ];
        }

        if (isset($filters['service'])) {
            $item = $services->firstWhere('id', (int) $filters['service']) ?? abort(404);

            return [
                ['type' => 'service', 'id' => $item->id, 'label' => $item->name, 'url' => route('clients.services.show', [$client, $item])],
                fn (Builder $query) => $query->where('service_id', $item->id),
            ];
        }

        return match ($filters['view'] ?? null) {
            'general' => [
                ['type' => 'general', 'id' => null, 'label' => 'Accès généraux du client'],
                fn (Builder $query) => $query->whereNull('equipment_id')->whereNull('service_id'),
            ],
            'unfiled' => [
                ['type' => 'unfiled', 'id' => null, 'label' => 'Non classés'],
                fn (Builder $query) => $query->whereNull('folder_id'),
            ],
            default => [
                ['type' => 'all', 'id' => null, 'label' => 'Tous les mots de passe'],
                fn (Builder $query) => $query,
            ],
        };
    }

    /**
     * Nombre d'accès par dossier, équipement et service (trois requêtes groupées).
     *
     * @return array{folders: array<int, int>, equipment: array<int, int>, services: array<int, int>, totals: array<string, int>}
     */
    private function counts(Client $client): array
    {
        $grouped = fn (string $column): array => Credential::query()
            ->where('client_id', $client->id)
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("{$column} as target, count(*) as total")
            ->toBase()
            ->pluck('total', 'target')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $totals = Credential::query()
            ->where('client_id', $client->id)
            ->toBase()
            ->selectRaw('count(*) as total_count')
            ->selectRaw('sum(case when equipment_id is null and service_id is null then 1 else 0 end) as general_count')
            ->selectRaw('sum(case when folder_id is null then 1 else 0 end) as unfiled_count')
            ->first();

        return [
            'folders' => $grouped('folder_id'),
            'equipment' => $grouped('equipment_id'),
            'services' => $grouped('service_id'),
            'totals' => [
                'all' => (int) ($totals->total_count ?? 0),
                'general' => (int) ($totals->general_count ?? 0),
                'unfiled' => (int) ($totals->unfiled_count ?? 0),
            ],
        ];
    }

    /**
     * Recherche sur les métadonnées uniquement : jamais sur les secrets.
     */
    private function matching(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '\\%_').'%';

        $query->where('label', 'like', $like)
            ->orWhere('username', 'like', $like)
            ->orWhere('host', 'like', $like)
            ->orWhere('url', 'like', $like)
            ->orWhere('notes', 'like', $like);
    }

    /**
     * Clients accessibles, chacun formant automatiquement un dossier racine.
     *
     * @return list<array<string, mixed>>
     */
    private function clientList(User $user): array
    {
        return Client::query()
            ->visibleTo($user)
            ->withCount('credentials')
            ->orderBy('name')
            ->get(['id', 'name', 'reference', 'status'])
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'reference' => $client->reference,
                'archived' => $client->isArchived(),
                'count' => $client->credentials_count,
            ])
            ->all();
    }

    /**
     * @return array{expires_in: int, idle_timeout: int}
     */
    private function lockState(Request $request): array
    {
        return [
            'expires_in' => VaultLock::expiresIn($request),
            'idle_timeout' => VaultLock::idleTimeout(),
        ];
    }
}
