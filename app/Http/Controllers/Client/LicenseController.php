<?php

namespace App\Http\Controllers\Client;

use App\Enums\BillingPeriod;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use App\Http\Controllers\Concerns\HasRecordExtensions;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\LicenseRequest;
use App\Models\Client;
use App\Models\IntegrationAccount;
use App\Models\License;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LicenseController extends Controller
{
    use HasRecordExtensions, InteractsWithClient;

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(LicenseKind::class)],
            'status' => ['nullable', Rule::in([...LicenseStatus::values(), 'current', 'all'])],
            'expiring' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'publisher', 'expires_at', 'quantity'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $status = $filters['status'] ?? 'current';

        $licenses = $client->licenses()
            ->when($status === 'current', fn (Builder $query) => $query->where('status', '!=', LicenseStatus::Archived))
            ->when(! in_array($status, ['current', 'all'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['expiring'] ?? false, fn (Builder $query) => $query->whereNotNull('expires_at')
                ->where('expires_at', '<=', today()->addDays(config('techindex.alerts.license_days'))))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")->orWhere('publisher', 'like', "%{$term}%")
                ->orWhere('supplier', 'like', "%{$term}%")->orWhere('contract_reference', 'like', "%{$term}%")))
            ->withCount('equipment')
            ->orderBy($filters['sort'] ?? 'expires_at', $filters['direction'] ?? 'asc')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Licenses/Index', [
            'context' => $this->context($client, $request),
            'licenses' => $licenses,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'kind' => $filters['kind'] ?? null,
                'status' => $status,
                'expiring' => (bool) ($filters['expiring'] ?? false),
                'sort' => $filters['sort'] ?? 'expires_at',
                'direction' => $filters['direction'] ?? 'asc',
            ],
            'kinds' => LicenseKind::options(),
            'statuses' => LicenseStatus::options(),
            'warnDays' => (int) config('techindex.alerts.license_days'),
        ]);
    }

    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('contribute', $client);

        return Inertia::render('Licenses/Form', [
            'context' => $this->context($client, $request),
            'license' => null,
            'extension' => $this->extensionForm('license', $client, null),
            ...$this->formOptions($client),
        ]);
    }

    public function store(LicenseRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $license = DB::transaction(function () use ($request, $client): License {
            $license = new License($request->safe()->except(['equipment_ids', 'fields']));
            $license->client()->associate($client);
            $license->save();
            $license->equipment()->sync($request->input('equipment_ids', []));
            $this->saveExtension('license', $license, $client, $request->validated(), true);

            return $license;
        });

        return redirect()->route('clients.licenses.show', [$client, $license])->with('success', 'Licence enregistrée.');
    }

    public function show(Request $request, Client $client, License $license): Response
    {
        Gate::authorize('view', $license);

        $license->load(['site:id,name', 'equipment:id,client_id,name,hostname,status', 'creator:id,name', 'editor:id,name']);

        return Inertia::render('Licenses/Show', [
            'context' => $this->context($client, $request),
            'license' => [
                ...$license->toArray(),
                'kind_label' => $license->kind->label(),
                'status_label' => $license->status->label(),
                'billing_period_label' => $license->billing_period?->label(),
            ],
            'source' => $this->source($license),
            'history' => $this->historyFor($license, $request->user()),
            'extension' => $this->extensionDisplay('license', $client, $license, ['kind' => $license->kind->value, 'status' => $license->status->value]),
            'warnDays' => (int) config('techindex.alerts.license_days'),
            'can' => [
                'update' => Gate::allows('update', $license),
                'delete' => Gate::allows('delete', $license),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, License $license): Response
    {
        Gate::authorize('update', $license);

        return Inertia::render('Licenses/Form', [
            'context' => $this->context($client, $request),
            'license' => [...$license->toArray(), 'equipment_ids' => $license->equipment()->pluck('equipment.id')],
            'source' => $this->source($license),
            'extension' => $this->extensionForm('license', $client, $license),
            ...$this->formOptions($client),
        ]);
    }

    public function update(LicenseRequest $request, Client $client, License $license): RedirectResponse
    {
        Gate::authorize('update', $license);

        DB::transaction(function () use ($request, $client, $license): void {
            $license->fill($request->safe()->except(['equipment_ids', 'fields']));
            $this->saveExtension('license', $license, $client, $request->validated(), false);
            $before = $license->equipment()->pluck('name')->sort()->implode(', ');
            $license->equipment()->sync($request->input('equipment_ids', []));
            $license->addAuditChange('equipment', $before ?: null, $license->equipment()->pluck('name')->sort()->implode(', ') ?: null);
            $license->save();
            $license->flushPendingAudit();
        });

        return redirect()->route('clients.licenses.show', [$client, $license])->with('success', 'Licence mise à jour.');
    }

    public function destroy(Request $request, Client $client, License $license): RedirectResponse
    {
        Gate::authorize('delete', $license);
        $this->confirmDeletion($request, $license->name);

        $license->delete();

        return redirect()->route('clients.licenses.index', $client)->with('success', 'Licence supprimée définitivement.');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Intégration d'où provient la licence (nom du connecteur et du compte).
     *
     * @return array{label: string, account: string, synced_at: ?string}|null
     */
    private function source(License $license): ?array
    {
        if (! $license->isSynced()) {
            return null;
        }

        $account = IntegrationAccount::query()->with('integration:id,provider')->find($license->integration_account_id, ['id', 'integration_id', 'name']);

        return $account === null ? null : [
            'label' => app(IntegrationRegistry::class)->label((string) $account->integration?->provider),
            'account' => $account->name,
            'synced_at' => $license->synced_at?->toIso8601String(),
        ];
    }

    private function formOptions(Client $client): array
    {
        return [
            'kinds' => LicenseKind::options(),
            'statuses' => LicenseStatus::options(),
            'periods' => BillingPeriod::options(),
            'sites' => $this->siteOptions($client),
            'equipment' => $this->equipmentOptions($client),
        ];
    }
}
