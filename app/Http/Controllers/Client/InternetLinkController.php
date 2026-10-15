<?php

namespace App\Http\Controllers\Client;

use App\Enums\AccessType;
use App\Enums\ClientPermission;
use App\Enums\InternetConnectionMode;
use App\Enums\InternetLinkRole;
use App\Enums\InternetLinkStatus;
use App\Enums\InternetTechnology;
use App\Http\Controllers\Concerns\HasRecordExtensions;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternetLinkRequest;
use App\Models\Client;
use App\Models\InternetLink;
use App\Models\Isp;
use App\Services\Tags\TagResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Liens d'accès à Internet d'un client (onglet « FAI »).
 */
class InternetLinkController extends Controller
{
    use HasRecordExtensions, InteractsWithClient;

    public function __construct(private readonly TagResolver $tags) {}

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...InternetLinkStatus::values(), 'current', 'all'])],
            'site' => ['nullable', 'integer'],
            'isp' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'current';

        $links = $client->internetLinks()
            ->when($status === 'current', fn (Builder $query) => $query->whereNotIn('status', [InternetLinkStatus::Archived, InternetLinkStatus::Terminated]))
            ->when(! in_array($status, ['current', 'all'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($filters['site'] ?? null, fn (Builder $query, int $site) => $query->where('site_id', $site))
            ->when($filters['isp'] ?? null, fn (Builder $query, int $isp) => $query->where('isp_id', $isp))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('operator_name', 'like', $like)
                    ->orWhere('line_reference', 'like', $like)
                    ->orWhere('customer_number', 'like', $like)
                    ->orWhere('contract_reference', 'like', $like)
                    ->orWhere('public_ips', 'like', $like)
                    ->orWhereHas('isp', fn (Builder $query) => $query->where('name', 'like', $like)));
            })
            ->with(['isp', 'site:id,name', 'tags:id,name,color'])
            ->withCount(['equipment', 'subnets'])
            ->orderByRaw("CASE role WHEN 'primary' THEN 0 WHEN 'aggregated' THEN 1 WHEN 'backup' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn (InternetLink $link): array => $this->summary($link));

        return Inertia::render('InternetLinks/Index', [
            'context' => $this->context($client, $request),
            'links' => $links,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $status,
                'site' => $filters['site'] ?? null,
                'isp' => $filters['isp'] ?? null,
            ],
            'statuses' => InternetLinkStatus::options(),
            'sites' => $this->siteOptions($client),
            'isps' => Isp::query()->whereIn('id', $client->internetLinks()->whereNotNull('isp_id')->select('isp_id'))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Isp $isp): array => ['value' => $isp->id, 'label' => $isp->name]),
        ]);
    }

    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('contribute', $client);

        return Inertia::render('InternetLinks/Form', [
            'context' => $this->context($client, $request),
            'link' => null,
            'extension' => $this->extensionForm('internet_link', $client, null),
            ...$this->formOptions($client, null),
        ]);
    }

    public function store(InternetLinkRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $link = DB::transaction(function () use ($request, $client): InternetLink {
            $link = new InternetLink($request->linkAttributes());
            $link->client()->associate($client);
            $link->save();
            $link->equipment()->sync($request->input('equipment_ids', []));
            $link->documents()->sync($request->input('document_ids', []));
            $link->tags()->sync($this->tags->idsFor($request->input('tags')));
            $this->saveExtension('internet_link', $link, $client, $request->validated(), true);

            return $link;
        });

        return redirect()->route('clients.internet-links.show', [$client, $link])->with('success', 'Lien Internet enregistré.');
    }

    public function show(Request $request, Client $client, InternetLink $internetLink): Response
    {
        Gate::authorize('view', $internetLink);

        $user = $request->user();
        $internetLink->load([
            'isp',
            'site:id,name',
            'tags:id,name,color',
            'equipment:id,client_id,name,hostname,status,equipment_type_id',
            'equipment.type:id,name,icon',
            'documents:id,client_id,title,status',
            'subnets:id,client_id,internet_link_id,name,cidr,gateway,purpose',
            'creator:id,name',
            'editor:id,name',
        ]);

        return Inertia::render('InternetLinks/Show', [
            'context' => $this->context($client, $request),
            'link' => [
                ...$this->summary($internetLink),
                ...$internetLink->only([
                    'operator_name', 'customer_number', 'contract_reference', 'connection_username', 'wan_vlan',
                    'wan_gateway', 'dns_servers', 'gtr', 'support_phone', 'monthly_cost', 'description', 'notes',
                    'guaranteed_mbps',
                ]),
                'guaranteed_label' => $internetLink->guaranteed_mbps !== null ? InternetLink::formatMbps($internetLink->guaranteed_mbps) : null,
                'connection_mode_label' => $internetLink->connection_mode?->label(),
                'commissioned_at' => $internetLink->commissioned_at?->format('Y-m-d'),
                'isp' => $internetLink->isp === null ? null : [
                    ...$internetLink->isp->only(['id', 'name', 'website', 'customer_area_url', 'status_url', 'support_phone', 'business_support_phone', 'support_email', 'notes']),
                    'logo_url' => $internetLink->isp->logoUrl(),
                ],
                'equipment' => $internetLink->equipment->map(fn ($equipment): array => [
                    ...$equipment->only(['id', 'name', 'hostname']),
                    'status' => $equipment->status->value,
                    'icon' => $equipment->type?->icon,
                    'type' => $equipment->type?->name,
                ]),
                'documents' => $internetLink->documents->map(fn ($document): array => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'status' => $document->status->value,
                ]),
                'subnets' => $internetLink->subnets->map(fn ($subnet): array => [
                    ...$subnet->only(['id', 'name', 'cidr', 'gateway']),
                    'purpose_label' => $subnet->purpose?->label(),
                ]),
                'created_at' => $internetLink->created_at?->toIso8601String(),
                'updated_at' => $internetLink->updated_at?->toIso8601String(),
                'creator' => $internetLink->creator?->only(['id', 'name']),
                'editor' => $internetLink->editor?->only(['id', 'name']),
            ],
            'credentials' => $this->presentCredentials($internetLink->credentials()->with(['equipment:id,name', 'service:id,name', 'internetLink:id,name'])->orderBy('label')->get(), $client, $user),
            'accessTypes' => AccessType::options(),
            'attachments' => $this->attachmentsFor($internetLink, $client, $user),
            'history' => $this->historyFor($internetLink, $user),
            'extension' => $this->extensionDisplay('internet_link', $client, $internetLink, [
                'technology' => $internetLink->technology?->value,
                'role' => $internetLink->role->value,
                'status' => $internetLink->status->value,
            ]),
            'commitmentWarnDays' => (int) config('techindex.alerts.license_days'),
            'can' => [
                'update' => Gate::allows('update', $internetLink),
                'delete' => Gate::allows('delete', $internetLink),
                'write_secrets' => $user->hasClientPermission($client, ClientPermission::Secrets) && Gate::allows('update', $internetLink),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, InternetLink $internetLink): Response
    {
        Gate::authorize('update', $internetLink);

        return Inertia::render('InternetLinks/Form', [
            'context' => $this->context($client, $request),
            'link' => [
                ...$internetLink->only([
                    'id', 'site_id', 'isp_id', 'operator_name', 'name', 'download_mbps', 'upload_mbps', 'guaranteed_mbps',
                    'customer_number', 'contract_reference', 'line_reference', 'connection_username', 'wan_vlan',
                    'public_ips', 'wan_gateway', 'dns_servers', 'gtr', 'support_phone', 'monthly_cost', 'description', 'notes',
                ]),
                'role' => $internetLink->role->value,
                'status' => $internetLink->status->value,
                'technology' => $internetLink->technology?->value,
                'connection_mode' => $internetLink->connection_mode?->value,
                'commissioned_at' => $internetLink->commissioned_at?->format('Y-m-d'),
                'commitment_ends_at' => $internetLink->commitment_ends_at?->format('Y-m-d'),
                'equipment_ids' => $internetLink->equipment()->pluck('equipment.id'),
                'document_ids' => $internetLink->documents()->pluck('documents.id'),
                'tags' => $internetLink->tags()->pluck('name'),
            ],
            'extension' => $this->extensionForm('internet_link', $client, $internetLink),
            ...$this->formOptions($client, $internetLink),
        ]);
    }

    public function update(InternetLinkRequest $request, Client $client, InternetLink $internetLink): RedirectResponse
    {
        Gate::authorize('update', $internetLink);

        DB::transaction(function () use ($request, $client, $internetLink): void {
            $internetLink->fill($request->linkAttributes());
            $this->saveExtension('internet_link', $internetLink, $client, $request->validated(), false);

            $before = $internetLink->equipment()->pluck('name')->sort()->implode(', ');
            $internetLink->equipment()->sync($request->input('equipment_ids', []));
            $internetLink->addAuditChange('equipment', $before ?: null, $internetLink->equipment()->pluck('name')->sort()->implode(', ') ?: null);

            $before = $internetLink->documents()->pluck('title')->sort()->implode(', ');
            $internetLink->documents()->sync($request->input('document_ids', []));
            $internetLink->addAuditChange('documents', $before ?: null, $internetLink->documents()->pluck('title')->sort()->implode(', ') ?: null);

            $internetLink->syncTagsWithAudit($this->tags->idsFor($request->input('tags')));
            $internetLink->save();
            $internetLink->flushPendingAudit();
        });

        return redirect()->route('clients.internet-links.show', [$client, $internetLink])->with('success', 'Lien Internet mis à jour.');
    }

    public function archive(Client $client, InternetLink $internetLink): RedirectResponse
    {
        Gate::authorize('archive', $internetLink);

        $internetLink->update(['status' => InternetLinkStatus::Archived]);

        return back()->with('success', 'Lien Internet archivé.');
    }

    public function destroy(Request $request, Client $client, InternetLink $internetLink): RedirectResponse
    {
        Gate::authorize('delete', $internetLink);
        $this->confirmDeletion($request, $internetLink->name);

        $internetLink->delete();

        return redirect()->route('clients.internet-links.index', $client)->with('success', 'Lien Internet supprimé définitivement.');
    }

    /**
     * Données communes à la liste et à la fiche.
     *
     * @return array<string, mixed>
     */
    private function summary(InternetLink $link): array
    {
        return [
            'id' => $link->id,
            'name' => $link->name,
            'operator' => $link->operatorLabel(),
            'logo_url' => $link->isp?->logoUrl(),
            'role' => $link->role->value,
            'role_label' => $link->role->label(),
            'status' => $link->status->value,
            'status_label' => $link->status->label(),
            'technology' => $link->technology?->value,
            'technology_label' => $link->technology?->label(),
            'download_mbps' => $link->download_mbps,
            'upload_mbps' => $link->upload_mbps,
            'bandwidth_label' => $link->bandwidthLabel(),
            'public_ips' => $link->public_ips,
            'line_reference' => $link->line_reference,
            'commitment_ends_at' => $link->commitment_ends_at?->format('Y-m-d'),
            'site' => $link->site?->only(['id', 'name']),
            'tags' => $link->relationLoaded('tags') ? $link->tags->map->only(['id', 'name', 'color'])->values() : [],
            'equipment_count' => $link->equipment_count ?? null,
            'subnets_count' => $link->subnets_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Client $client, ?InternetLink $link): array
    {
        return [
            'isps' => $this->ispOptions($link?->isp_id),
            'roles' => InternetLinkRole::options(),
            'statuses' => InternetLinkStatus::options(),
            'technologies' => InternetTechnology::options(),
            'connectionModes' => InternetConnectionMode::options(),
            'sites' => $this->siteOptions($client),
            'equipment' => $this->equipmentOptions($client),
            'documents' => $client->documents()->orderBy('title')->get(['id', 'title'])
                ->map(fn ($document): array => ['value' => $document->id, 'label' => $document->title]),
            'tags' => $this->tags->options(),
            'canManageCatalog' => Gate::allows('manage-settings'),
        ];
    }
}
