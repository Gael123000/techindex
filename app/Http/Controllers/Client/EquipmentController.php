<?php

namespace App\Http\Controllers\Client;

use App\Enums\AccessType;
use App\Enums\ClientPermission;
use App\Enums\EquipmentLinkType;
use App\Enums\EquipmentStatus;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\EquipmentRequest;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\Equipment;
use App\Models\EquipmentLink;
use App\Models\EquipmentType;
use App\Services\Inventory\EquipmentQuery;
use App\Services\Inventory\EquipmentService;
use App\Services\Tags\TagResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentController extends Controller
{
    use InteractsWithClient;

    public function __construct(
        private readonly EquipmentService $service,
        private readonly TagResolver $tags,
    ) {}

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate(EquipmentQuery::rules());
        unset($filters['client']);

        $equipment = EquipmentQuery::apply($client->equipment()->getQuery(), $filters)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Equipment $item): array => EquipmentQuery::present($item));

        return Inertia::render('Equipment/Index', [
            'context' => $this->context($client, $request),
            'equipment' => $equipment,
            'filters' => EquipmentQuery::normalizedFilters($filters),
            'options' => [
                'types' => EquipmentType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (EquipmentType $type): array => ['value' => $type->id, 'label' => $type->name]),
                'sites' => $this->siteOptions($client),
                'statuses' => EquipmentStatus::options(),
                'tags' => $this->tags->options(),
            ],
        ]);
    }

    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('contribute', $client);

        return Inertia::render('Equipment/Form', [
            'context' => $this->context($client, $request),
            'equipment' => null,
            ...$this->formOptions($client),
        ]);
    }

    public function store(EquipmentRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $equipment = $this->service->save(new Equipment, $client, $request->validated(), $request->customFields());

        return redirect()->route('clients.equipment.show', [$client, $equipment])->with('success', 'Équipement créé.');
    }

    public function show(Request $request, Client $client, Equipment $equipment): Response
    {
        Gate::authorize('view', $equipment);

        $user = $request->user();
        $equipment->load([
            'type:id,name,icon',
            'site:id,name',
            'tags:id,name,color',
            'interfaces.subnet:id,name,cidr',
            'customValues',
            'outgoingLinks.target:id,name,hostname,status',
            'incomingLinks.source:id,name,hostname,status',
            'services:id,client_id,name,category,status',
            'licenses:id,client_id,name,kind,expires_at,status',
            'certificates:id,client_id,name,common_name,expires_at,status',
            'documents:id,client_id,title,status',
            'creator:id,name',
            'editor:id,name',
        ]);

        $fields = CustomField::query()->forType($equipment->equipment_type_id)->get();
        $values = $equipment->customValues->keyBy('custom_field_id');

        $links = $equipment->outgoingLinks->map(fn (EquipmentLink $link): array => [
            'id' => $link->id,
            'label' => $link->type->label(),
            'other' => $link->target?->only(['id', 'name', 'hostname']),
            'notes' => $link->notes,
        ])->concat($equipment->incomingLinks->map(fn (EquipmentLink $link): array => [
            'id' => $link->id,
            'label' => $link->type->inverseLabel(),
            'other' => $link->source?->only(['id', 'name', 'hostname']),
            'notes' => $link->notes,
        ]))->values();

        return Inertia::render('Equipment/Show', [
            'context' => $this->context($client, $request),
            'equipment' => [
                ...$equipment->only([
                    'id', 'name', 'hostname', 'manufacturer', 'model', 'serial_number', 'inventory_number', 'location',
                    'os_name', 'os_version', 'firmware_version', 'role', 'description', 'supplier', 'admin_url', 'notes',
                ]),
                'status' => $equipment->status->value,
                'status_label' => $equipment->status->label(),
                'purchase_date' => $equipment->purchase_date?->format('Y-m-d'),
                'commissioning_date' => $equipment->commissioning_date?->format('Y-m-d'),
                'warranty_expires_at' => $equipment->warranty_expires_at?->format('Y-m-d'),
                'end_of_life_at' => $equipment->end_of_life_at?->format('Y-m-d'),
                'type' => $equipment->type?->only(['id', 'name', 'icon']),
                'site' => $equipment->site?->only(['id', 'name']),
                'tags' => $equipment->tags,
                'interfaces' => $equipment->interfaces,
                'created_at' => $equipment->created_at?->toIso8601String(),
                'updated_at' => $equipment->updated_at?->toIso8601String(),
                'creator' => $equipment->creator?->only(['id', 'name']),
                'editor' => $equipment->editor?->only(['id', 'name']),
            ],
            'customFields' => $fields->map(fn (CustomField $field): array => [
                'label' => $field->label,
                'type' => $field->type->value,
                'value' => $values->get($field->id)?->displayValue($field->type),
            ]),
            'links' => $links,
            'services' => $equipment->services->map(fn ($service): array => [
                'id' => $service->id, 'name' => $service->name, 'category' => $service->category->label(), 'status' => $service->status->value,
            ]),
            'licenses' => $equipment->licenses,
            'certificates' => $equipment->certificates,
            'documents' => $equipment->documents,
            'credentials' => $this->presentCredentials($equipment->credentials()->orderBy('label')->get(), $client, $user),
            'accessTypes' => AccessType::options(),
            'attachments' => $this->attachmentsFor($equipment, $client, $user),
            'history' => $this->historyFor($equipment, $user),
            'linkOptions' => [
                'types' => EquipmentLinkType::options(),
                'equipment' => $this->equipmentOptions($client)->reject(fn (array $option): bool => $option['value'] === $equipment->id)->values(),
            ],
            'can' => [
                'update' => Gate::allows('update', $equipment),
                'delete' => Gate::allows('delete', $equipment),
                'write_secrets' => $user->hasClientPermission($client, ClientPermission::Secrets) && Gate::allows('update', $equipment),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, Equipment $equipment): Response
    {
        Gate::authorize('update', $equipment);

        $equipment->load(['tags:id,name', 'interfaces', 'customValues']);
        $values = $equipment->customValues->keyBy('custom_field_id');
        $fields = CustomField::query()->get();

        return Inertia::render('Equipment/Form', [
            'context' => $this->context($client, $request),
            'equipment' => [
                ...$equipment->only([
                    'id', 'equipment_type_id', 'site_id', 'name', 'hostname', 'manufacturer', 'model', 'serial_number',
                    'inventory_number', 'location', 'os_name', 'os_version', 'firmware_version', 'role', 'description',
                    'supplier', 'admin_url', 'notes',
                ]),
                'status' => $equipment->status->value,
                'purchase_date' => $equipment->purchase_date?->format('Y-m-d'),
                'commissioning_date' => $equipment->commissioning_date?->format('Y-m-d'),
                'warranty_expires_at' => $equipment->warranty_expires_at?->format('Y-m-d'),
                'end_of_life_at' => $equipment->end_of_life_at?->format('Y-m-d'),
                'tags' => $equipment->tags->pluck('name'),
                'interfaces' => $equipment->interfaces->map->only(['label', 'ip_address', 'mac_address', 'subnet_id', 'is_primary', 'notes']),
                'custom_fields' => $fields->mapWithKeys(fn (CustomField $field): array => [
                    $field->id => $values->get($field->id)?->value($field->type),
                ]),
            ],
            ...$this->formOptions($client),
        ]);
    }

    public function update(EquipmentRequest $request, Client $client, Equipment $equipment): RedirectResponse
    {
        Gate::authorize('update', $equipment);

        $this->service->save($equipment, $client, $request->validated(), $request->customFields());

        return redirect()->route('clients.equipment.show', [$client, $equipment])->with('success', 'Équipement mis à jour.');
    }

    public function archive(Client $client, Equipment $equipment): RedirectResponse
    {
        Gate::authorize('archive', $equipment);

        $equipment->update(['status' => EquipmentStatus::Archived]);

        return back()->with('success', 'Équipement archivé.');
    }

    public function destroy(Request $request, Client $client, Equipment $equipment): RedirectResponse
    {
        Gate::authorize('delete', $equipment);
        $this->confirmDeletion($request, $equipment->name);

        $equipment->delete();

        return redirect()->route('clients.equipment.index', $client)->with('success', 'Équipement supprimé définitivement.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Client $client): array
    {
        return [
            'types' => EquipmentType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'icon']),
            'sites' => $this->siteOptions($client),
            'subnets' => $client->subnets()->orderBy('cidr')->get(['id', 'name', 'cidr'])
                ->map(fn ($subnet): array => ['value' => $subnet->id, 'label' => $subnet->name.' ('.$subnet->cidr.')']),
            'statuses' => EquipmentStatus::options(),
            'customFields' => CustomField::query()->orderBy('sort_order')->orderBy('label')
                ->get(['id', 'equipment_type_id', 'key', 'label', 'type', 'options', 'is_required', 'help_text']),
            'tags' => $this->tags->options(),
            'suggestions' => [
                'manufacturers' => $client->equipment()->whereNotNull('manufacturer')->distinct()->orderBy('manufacturer')->limit(100)->pluck('manufacturer'),
                'suppliers' => $client->equipment()->whereNotNull('supplier')->distinct()->orderBy('supplier')->limit(100)->pluck('supplier'),
                'os' => $client->equipment()->whereNotNull('os_name')->distinct()->orderBy('os_name')->limit(100)->pluck('os_name'),
            ],
        ];
    }
}
