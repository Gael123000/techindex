<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
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

        $clients = Client::query()
            ->visibleTo($request->user())
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('reference', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')))
            ->when($filters['manager'] ?? null, fn (Builder $query, int $manager) => $query->where('account_manager_id', $manager))
            ->when($filters['tag'] ?? null, fn (Builder $query, int $tag) => $query->whereHas('tags', fn (Builder $query) => $query->whereKey($tag)))
            ->with(['accountManager:id,name', 'tags:id,name,color'])
            ->withCount(['sites', 'equipment'])
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
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

        return Inertia::render('Clients/Form', [
            'client' => null,
            'defaults' => ['reference' => Client::nextReference(), 'account_manager_id' => $request->user()->id, 'country' => 'France'],
            'managers' => $this->managerOptions(),
            'tags' => $this->tags->options(),
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
                'postal_code', 'city', 'country', 'phone', 'email', 'website', 'notes',
            ]) + [
                'tags' => $client->tags()->pluck('name'),
                'logo_url' => $client->hasLogo() ? route('clients.logo', ['client' => $client, 'v' => $client->updated_at?->getTimestamp()]) : null,
            ],
            'context' => $this->context($client, $request),
            'defaults' => null,
            'managers' => $this->managerOptions(),
            'tags' => $this->tags->options(),
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
     * @return Collection<int, array{value: int, label: string}>
     */
    private function managerOptions(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name]);
    }
}
