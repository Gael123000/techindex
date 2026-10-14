<?php

namespace App\Http\Controllers\Client;

use App\Enums\AccessType;
use App\Enums\ClientPermission;
use App\Enums\ServiceCategory;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest;
use App\Models\Client;
use App\Models\Service;
use App\Services\Tags\TagResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    use InteractsWithClient;

    public function __construct(private readonly TagResolver $tags) {}

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::enum(ServiceCategory::class)],
            'status' => ['nullable', Rule::in([...ServiceStatus::values(), 'current', 'all'])],
            'site' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(['name', 'provider', 'end_date', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $status = $filters['status'] ?? 'current';

        $services = $client->services()
            ->when($status === 'current', fn (Builder $query) => $query->where('status', '!=', ServiceStatus::Archived))
            ->when(! in_array($status, ['current', 'all'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['site'] ?? null, fn (Builder $query, int $site) => $query->where('site_id', $site))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")->orWhere('provider', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%")))
            ->with(['site:id,name', 'tags:id,name,color'])
            ->withCount('equipment')
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Services/Index', [
            'context' => $this->context($client, $request),
            'services' => $services,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'category' => $filters['category'] ?? null,
                'status' => $status,
                'site' => $filters['site'] ?? null,
                'sort' => $filters['sort'] ?? 'name',
                'direction' => $filters['direction'] ?? 'asc',
            ],
            'categories' => ServiceCategory::definitions(),
            'statuses' => ServiceStatus::options(),
            'sites' => $this->siteOptions($client),
        ]);
    }

    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('contribute', $client);

        return Inertia::render('Services/Form', [
            'context' => $this->context($client, $request),
            'service' => null,
            'defaultCategory' => ServiceCategory::tryFrom((string) $request->query('category'))?->value,
            ...$this->formOptions($client),
        ]);
    }

    public function store(ServiceRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $service = DB::transaction(function () use ($request, $client): Service {
            $service = new Service($this->attributes($request));
            $service->client()->associate($client);
            $service->save();
            $service->equipment()->sync($request->input('equipment_ids', []));
            $service->tags()->sync($this->tags->idsFor($request->input('tags')));

            return $service;
        });

        return redirect()->route('clients.services.show', [$client, $service])->with('success', 'Service créé.');
    }

    public function show(Request $request, Client $client, Service $service): Response
    {
        Gate::authorize('view', $service);

        $user = $request->user();
        $service->load([
            'site:id,name',
            'tags:id,name,color',
            'equipment:id,client_id,name,hostname,status',
            'documents:id,client_id,title,status',
            'certificates:id,client_id,service_id,name,expires_at,status',
            'creator:id,name',
            'editor:id,name',
        ]);

        return Inertia::render('Services/Show', [
            'context' => $this->context($client, $request),
            'service' => [
                ...$service->only(['id', 'name', 'provider', 'reference', 'url', 'description', 'notes', 'details']),
                'category' => $service->category->value,
                'category_label' => $service->category->label(),
                'status' => $service->status->value,
                'status_label' => $service->status->label(),
                'start_date' => $service->start_date?->format('Y-m-d'),
                'end_date' => $service->end_date?->format('Y-m-d'),
                'site' => $service->site?->only(['id', 'name']),
                'tags' => $service->tags,
                'equipment' => $service->equipment,
                'documents' => $service->documents,
                'certificates' => $service->certificates,
                'created_at' => $service->created_at?->toIso8601String(),
                'updated_at' => $service->updated_at?->toIso8601String(),
                'creator' => $service->creator?->only(['id', 'name']),
                'editor' => $service->editor?->only(['id', 'name']),
            ],
            'definition' => collect(ServiceCategory::definitions())->firstWhere('value', $service->category->value),
            'credentials' => $this->presentCredentials($service->credentials()->orderBy('label')->get(), $client, $user),
            'accessTypes' => AccessType::options(),
            'attachments' => $this->attachmentsFor($service, $client, $user),
            'history' => $this->historyFor($service, $user),
            'can' => [
                'update' => Gate::allows('update', $service),
                'delete' => Gate::allows('delete', $service),
                'write_secrets' => $user->hasClientPermission($client, ClientPermission::Secrets) && Gate::allows('update', $service),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, Service $service): Response
    {
        Gate::authorize('update', $service);

        return Inertia::render('Services/Form', [
            'context' => $this->context($client, $request),
            'service' => [
                ...$service->only(['id', 'site_id', 'name', 'provider', 'reference', 'url', 'description', 'notes']),
                'category' => $service->category->value,
                'status' => $service->status->value,
                'start_date' => $service->start_date?->format('Y-m-d'),
                'end_date' => $service->end_date?->format('Y-m-d'),
                'details' => (object) ($service->details ?? []),
                'equipment_ids' => $service->equipment()->pluck('equipment.id'),
                'tags' => $service->tags()->pluck('name'),
            ],
            'defaultCategory' => null,
            ...$this->formOptions($client),
        ]);
    }

    public function update(ServiceRequest $request, Client $client, Service $service): RedirectResponse
    {
        Gate::authorize('update', $service);

        DB::transaction(function () use ($request, $service): void {
            $service->fill($this->attributes($request));

            $before = $service->equipment()->pluck('name')->sort()->implode(', ');
            $service->equipment()->sync($request->input('equipment_ids', []));
            $after = $service->equipment()->pluck('name')->sort()->implode(', ');
            $service->addAuditChange('equipment', $before ?: null, $after ?: null);

            $service->syncTagsWithAudit($this->tags->idsFor($request->input('tags')));
            $service->save();
            $service->flushPendingAudit();
        });

        return redirect()->route('clients.services.show', [$client, $service])->with('success', 'Service mis à jour.');
    }

    public function archive(Client $client, Service $service): RedirectResponse
    {
        Gate::authorize('archive', $service);

        $service->update(['status' => ServiceStatus::Archived]);

        return back()->with('success', 'Service archivé.');
    }

    public function destroy(Request $request, Client $client, Service $service): RedirectResponse
    {
        Gate::authorize('delete', $service);
        $this->confirmDeletion($request, $service->name);

        $service->delete();

        return redirect()->route('clients.services.index', $client)->with('success', 'Service supprimé définitivement.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ServiceRequest $request): array
    {
        $category = ServiceCategory::from($request->input('category'));
        $data = $request->safe()->except(['equipment_ids', 'tags', 'details']);
        $details = $category->normalizeDetails($request->input('details'));
        $data['details'] = $details === [] ? null : $details;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Client $client): array
    {
        return [
            'categories' => ServiceCategory::definitions(),
            'statuses' => ServiceStatus::options(),
            'sites' => $this->siteOptions($client),
            'equipment' => $this->equipmentOptions($client),
            'tags' => $this->tags->options(),
        ];
    }
}
