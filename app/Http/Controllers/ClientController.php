<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Enums\ClientGroupRole;
use App\Enums\ClientPermission;
use App\Enums\EquipmentStatus;
use App\Enums\LicenseStatus;
use App\Enums\RecordStatus;
use App\Http\Controllers\Concerns\HasRecordExtensions;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Requests\ClientRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\User;
use App\Services\Attachments\AttachmentStorage;
use App\Services\Audit\AuditLogger;
use App\Services\Tags\TagResolver;
use App\Support\AuditPresenter;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    use HasRecordExtensions, InteractsWithClient;

    private const SORTS = ['name', 'reference', 'city', 'updated_at'];

    public function __construct(
        private readonly TagResolver $tags,
        private readonly AttachmentStorage $storage,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'manager' => ['nullable', 'integer'],
            'tag' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $status = $filters['status'] ?? 'active';
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        $user = $request->user();

        // Sans recherche ni filtre, les entreprises rattachées s'affichent sous leur entreprise mère.
        $tree = empty($filters['q']) && empty($filters['manager']) && empty($filters['tag']);

        $listed = fn (): Builder => Client::query()
            ->visibleTo($user)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status));

        $clients = $listed()
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('reference', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')))
            ->when($filters['manager'] ?? null, fn (Builder $query, int $manager) => $query->where('account_manager_id', $manager))
            ->when($filters['tag'] ?? null, fn (Builder $query, int $tag) => $query->whereHas('tags', fn (Builder $query) => $query->whereKey($tag)))
            // Une entreprise dont la mère n'est pas listée (non attribuée, archivée) apparaît au premier niveau.
            ->when($tree, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('parent_id')
                ->orWhereNotIn('parent_id', $listed()->select('id'))))
            ->with(['accountManager:id,name', 'tags:id,name,color', ...($tree ? [] : ['parent:id,name'])])
            ->withCount(['sites', 'equipment'])
            ->orderBy($sort, $direction)
            ->paginate(25)
            ->withQueryString();

        $members = $tree ? $this->groupMembers($listed, $clients->getCollection()->modelKeys(), $sort, $direction) : collect();
        $visibleIds = $user->clientIdsWith(ClientPermission::View);
        $clients->through(fn (Client $client): array => $this->listRow($client, $members, $tree ? null : $visibleIds));

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'tree' => $tree,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $status,
                'manager' => $filters['manager'] ?? null,
                'tag' => $filters['tag'] ?? null,
                'sort' => $filters['sort'] ?? 'name',
                'direction' => $filters['direction'] ?? 'asc',
            ],
            'managers' => $this->managerOptions(),
            'tags' => $this->tags->options(),
            'can' => [
                'create' => Gate::allows('create', Client::class),
                'export' => $request->user()->clientIdsWith(ClientPermission::Export) !== [],
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Client::class);

        $user = $request->user();
        // « Ajouter une entreprise au groupe » depuis la fiche de l'entreprise mère.
        $parentId = $request->integer('parent') ?: null;

        if ($parentId !== null && ! $user->hasClientPermission($parentId, ClientPermission::View)) {
            $parentId = null;
        }

        return Inertia::render('Clients/Form', [
            'client' => null,
            'defaults' => [
                'reference' => Client::nextReference(),
                'account_manager_id' => $user->id,
                'country' => 'France',
                'parent_id' => $parentId,
                'group_role' => $parentId === null ? null : ClientGroupRole::Subsidiary->value,
            ],
            'managers' => $this->managerOptions(),
            'tags' => $this->tags->options(),
            'group' => $this->groupForm($user, null),
            'extension' => $this->extensionForm('client', new Client, null),
        ]);
    }

    public function store(ClientRequest $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $user = $request->user();

        $client = DB::transaction(function () use ($request, $user, $audit): Client {
            $client = new Client($request->safe()->except(['tags', 'logo', 'remove_logo', 'fields']));
            $client->status = RecordStatus::Active;
            $this->storeLogo($request, $client);
            $client->save();
            $this->saveExtension('client', $client, $client, $request->validated(), true);

            $client->tags()->sync($this->tags->idsFor($request->input('tags')));

            // Le responsable qui crée un client en obtient la gestion complète.
            if (! $user->isAdmin()) {
                $assignment = ClientAssignment::query()->create([
                    'client_id' => $client->id,
                    'user_id' => $user->id,
                    ...$user->role->defaultClientPermissions(),
                ]);

                $audit->log('access.granted', $user, ['access' => ['old' => null, 'new' => $assignment->summary()]], clientId: $client->id, label: $user->name);
                $user->flushAccessCache();
            }

            return $client;
        });

        return redirect()->route('clients.show', $client)->with('success', 'Client créé.');
    }

    public function show(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);

        $user = $request->user();
        $client->load(['accountManager:id,name,email', 'tags:id,name,color']);
        $horizon = today()->addDays(60);

        return Inertia::render('Clients/Show', [
            'context' => $this->context($client, $request),
            'client' => $client->only([
                'id', 'name', 'reference', 'description', 'status', 'archived_at', 'address_line1', 'address_line2',
                'postal_code', 'city', 'country', 'phone', 'email', 'website', 'notes', 'created_at', 'updated_at',
            ]) + [
                'account_manager' => $client->accountManager?->only(['id', 'name', 'email']),
                'tags' => $client->tags,
            ],
            'contacts' => $client->contacts()->with('site:id,name')->orderByDesc('is_primary')->orderByDesc('is_technical')->orderBy('last_name')->limit(6)->get(),
            'sites' => $client->sites()->where('status', RecordStatus::Active)->withCount('equipment')->orderBy('name')->limit(8)->get(['id', 'client_id', 'name', 'city', 'status']),
            'upcoming' => [
                'warranties' => $client->equipment()->where('status', '!=', EquipmentStatus::Archived)->whereNotNull('warranty_expires_at')
                    ->where('warranty_expires_at', '<=', $horizon)->where('warranty_expires_at', '>=', today()->subDays(30))
                    ->orderBy('warranty_expires_at')->limit(5)->get(['id', 'client_id', 'name', 'warranty_expires_at']),
                'licenses' => $client->licenses()->where('status', LicenseStatus::Active)->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $horizon)->orderBy('expires_at')->limit(5)->get(['id', 'client_id', 'name', 'expires_at']),
                'certificates' => $client->certificates()->where('status', CertificateStatus::Active)->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $horizon)->orderBy('expires_at')->limit(5)->get(['id', 'client_id', 'name', 'expires_at']),
            ],
            'group' => $this->groupDisplay($client, $user),
            'attachments' => $this->attachmentsFor($client, $client, $user),
            'extension' => $this->extensionDisplay('client', $client, $client),
            'history' => AuditLog::query()->where('client_id', $client->id)->with('user:id,name')->latest('id')->limit(8)->get()
                ->map(fn (AuditLog $log): array => AuditPresenter::present($log, $user)),
            'can' => [
                'update' => Gate::allows('update', $client),
                'archive' => Gate::allows('archive', $client),
                'restore' => Gate::allows('restore', $client),
                'delete' => Gate::allows('delete', $client),
                'export' => Gate::allows('export', $client),
            ],
        ]);
    }

    public function edit(Request $request, Client $client): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('Clients/Form', [
            'client' => $client->only([
                'id', 'name', 'reference', 'description', 'account_manager_id', 'address_line1', 'address_line2',
                'postal_code', 'city', 'country', 'phone', 'email', 'website', 'notes', 'parent_id',
            ]) + [
                'group_role' => $client->group_role?->value,
                'tags' => $client->tags()->pluck('name'),
                'logo_url' => $client->hasLogo() ? route('clients.logo', ['client' => $client, 'v' => $client->updated_at?->getTimestamp()]) : null,
            ],
            'context' => $this->context($client, $request),
            'defaults' => null,
            'managers' => $this->managerOptions(),
            'tags' => $this->tags->options(),
            'group' => $this->groupForm($request->user(), $client),
            'extension' => $this->extensionForm('client', $client, $client),
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        DB::transaction(function () use ($request, $client): void {
            $client->fill($request->safe()->except(['tags', 'logo', 'remove_logo', 'fields']));
            $this->saveExtension('client', $client, $client, $request->validated(), false);

            if ($request->boolean('remove_logo') && $client->logo_path !== null) {
                $this->storage->disk()->delete($client->logo_path);
                $client->logo_path = null;
                $client->addAuditChange('logo', 'présent', 'supprimé');
            }

            if ($this->storeLogo($request, $client)) {
                $client->addAuditChange('logo', null, 'remplacé');
            }

            $client->syncTagsWithAudit($this->tags->idsFor($request->input('tags')));
            $client->save();
            $client->flushPendingAudit();
        });

        return redirect()->route('clients.show', $client)->with('success', 'Client mis à jour.');
    }

    public function archive(Client $client): RedirectResponse
    {
        Gate::authorize('archive', $client);

        $client->forceFill(['status' => RecordStatus::Archived, 'archived_at' => now()])->save();

        return redirect()->route('clients.show', $client)->with('success', 'Client archivé. Ses données restent consultables.');
    }

    public function restore(Client $client): RedirectResponse
    {
        Gate::authorize('restore', $client);

        $client->forceFill(['status' => RecordStatus::Active, 'archived_at' => null])->save();

        return redirect()->route('clients.show', $client)->with('success', 'Client restauré.');
    }

    /**
     * Suppression définitive : administrateur, client archivé, nom recopié.
     */
    public function destroy(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('delete', $client);
        $this->confirmDeletion($request, $client->name);

        DB::transaction(function () use ($client): void {
            // Les entreprises rattachées deviennent indépendantes (changement tracé dans leur historique).
            foreach ($client->children()->get() as $member) {
                $member->forceFill(['parent_id' => null, 'group_role' => null])->save();
            }

            $this->storage->deleteClientFiles($client);
            $client->delete();
        });

        return redirect()->route('clients.index', ['status' => 'archived'])->with('success', 'Client supprimé définitivement.');
    }

    public function logo(Client $client): StreamedResponse
    {
        Gate::authorize('view', $client);

        abort_if($client->logo_path === null || ! $this->storage->disk()->exists($client->logo_path), 404);

        $mime = $this->storage->disk()->mimeType($client->logo_path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return $this->storage->disk()->response($client->logo_path, null, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }

    private function storeLogo(ClientRequest $request, Client $client): bool
    {
        if (! $request->hasFile('logo')) {
            return false;
        }

        if ($client->logo_path !== null) {
            $this->storage->disk()->delete($client->logo_path);
        }

        $client->logo_path = $request->file('logo')->storeAs(
            'logos',
            Str::uuid().'.'.$request->file('logo')->extension(),
            config('techindex.attachments.disk'),
        );

        return true;
    }

    /**
     * Entreprises rattachées aux clients de la page, niveau par niveau, parmi
     * les clients listés (visibles et au statut demandé), groupées par mère.
     *
     * @param  Closure(): Builder<Client>  $listed
     * @param  list<int>  $rootIds
     * @return Collection<int, list<Client>>
     */
    private function groupMembers(Closure $listed, array $rootIds, string $sort, string $direction): Collection
    {
        $byParent = collect();
        $level = $rootIds;

        for ($depth = 1; $depth < Client::MAX_GROUP_DEPTH && $level !== []; $depth++) {
            $members = $listed()
                ->whereIn('parent_id', $level)
                ->with(['accountManager:id,name', 'tags:id,name,color'])
                ->withCount(['sites', 'equipment'])
                ->orderBy($sort, $direction)
                ->get();

            foreach ($members as $member) {
                $byParent->put($member->parent_id, [...$byParent->get($member->parent_id, []), $member]);
            }

            $level = $members->modelKeys();
        }

        return $byParent;
    }

    /**
     * Ligne de la liste des clients, avec ses entreprises rattachées (arbre)
     * ou le nom de son entreprise mère (liste filtrée, si elle est visible).
     *
     * @param  Collection<int, list<Client>>  $members
     * @param  list<int>|null  $visibleIds
     * @return array<string, mixed>
     */
    private function listRow(Client $client, Collection $members, ?array $visibleIds): array
    {
        $parentVisible = $client->relationLoaded('parent') && $client->parent !== null
            && ($visibleIds === null || in_array($client->parent_id, $visibleIds, true));

        return [
            ...$client->only(['id', 'name', 'reference', 'city', 'status', 'updated_at', 'sites_count', 'equipment_count']),
            'account_manager' => $client->accountManager?->only(['id', 'name']),
            'tags' => $client->tags,
            'group_role' => $client->group_role?->value,
            'group_role_label' => $client->group_role?->shortLabel(),
            'parent' => $parentVisible ? $client->parent->only(['id', 'name']) : null,
            'children' => array_map(
                fn (Client $member): array => $this->listRow($member, $members, $visibleIds),
                $members->get($client->id, []),
            ),
        ];
    }

    /**
     * Données du choix de l'entreprise mère : clients visibles, hors client
     * lui-même et ses entreprises rattachées, dans la limite des niveaux.
     *
     * @return array<string, mixed>
     */
    private function groupForm(User $user, ?Client $client): array
    {
        $parents = Client::query()->pluck('parent_id', 'id');
        $depth = function (int $id) use ($parents): int {
            $level = 1;

            for ($parent = $parents[$id] ?? null; $parent !== null && $level < 10; $parent = $parents[$parent] ?? null) {
                $level++;
            }

            return $level;
        };

        $excluded = $client === null ? [] : [$client->id, ...$client->descendantIds()];
        $height = $client?->groupHeight() ?? 0;

        $options = Client::query()
            ->visibleTo($user)
            ->whereNotIn('id', $excluded)
            ->where(fn (Builder $query) => $query
                ->where('status', RecordStatus::Active)
                ->when($client?->parent_id, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->orderBy('name')
            ->get(['id', 'name', 'reference', 'parent_id'])
            ->filter(fn (Client $candidate): bool => $candidate->id === $client?->parent_id || $depth($candidate->id) + 1 + $height <= Client::MAX_GROUP_DEPTH)
            ->map(fn (Client $candidate): array => [
                'value' => $candidate->id,
                'label' => $candidate->name.' ('.$candidate->reference.')'.($candidate->parent_id !== null ? ' · rattaché' : ''),
            ])
            ->values();

        return [
            'parents' => $options,
            'roles' => ClientGroupRole::options(),
            'head_roles' => array_map(fn (ClientGroupRole $role): string => $role->value, ClientGroupRole::heads()),
            'member_roles' => array_map(fn (ClientGroupRole $role): string => $role->value, ClientGroupRole::members()),
            // Entreprise mère non attribuée à l'utilisateur : rattachement affiché sans être modifiable.
            'parent_hidden' => $client?->parent_id !== null && ! $user->hasClientPermission($client->parent_id, ClientPermission::View),
            'has_members' => $client !== null && $client->children()->exists(),
        ];
    }

    /**
     * Place du client dans son groupe, limitée aux entreprises visibles.
     *
     * @return array<string, mixed>
     */
    private function groupDisplay(Client $client, User $user): array
    {
        $visibleIds = $user->clientIdsWith(ClientPermission::View);
        $visible = fn (?int $id): bool => $id !== null && ($visibleIds === null || in_array($id, $visibleIds, true));
        $parent = $visible($client->parent_id) ? $client->parent()->first(['id', 'name', 'reference', 'group_role']) : null;

        $members = $client->children()
            ->visibleTo($user)
            ->withCount('children')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'reference', 'city', 'status', 'group_role'])
            ->map(fn (Client $member): array => [
                ...$member->only(['id', 'name', 'reference', 'city', 'status']),
                'group_role_label' => $member->group_role?->shortLabel(),
                'members_count' => $member->children_count,
            ]);

        return [
            'role' => $client->group_role?->value,
            'role_label' => $client->group_role?->shortLabel(),
            'parent' => $parent === null ? null : [
                ...$parent->only(['id', 'name', 'reference']),
                'group_role_label' => $parent->group_role?->shortLabel(),
            ],
            'parent_hidden' => $client->parent_id !== null && $parent === null,
            'members' => $members,
            'can_add_member' => Gate::allows('create', Client::class) && ! $client->isArchived() && $client->groupDepth() < Client::MAX_GROUP_DEPTH,
        ];
    }

    /**
     * @return Collection<int, array{value: int, label: string}>
     */
    private function managerOptions(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name]);
    }
}
