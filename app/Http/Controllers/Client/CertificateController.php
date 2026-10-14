<?php

namespace App\Http\Controllers\Client;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\CertificateRequest;
use App\Models\Certificate;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CertificateController extends Controller
{
    use InteractsWithClient;

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...CertificateStatus::values(), 'current', 'all'])],
            'expiring' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'common_name', 'expires_at', 'issuer'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $status = $filters['status'] ?? 'current';

        $certificates = $client->certificates()
            ->when($status === 'current', fn (Builder $query) => $query->where('status', '!=', CertificateStatus::Archived))
            ->when(! in_array($status, ['current', 'all'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($filters['expiring'] ?? false, fn (Builder $query) => $query->whereNotNull('expires_at')
                ->where('expires_at', '<=', today()->addDays(config('techindex.alerts.certificate_days'))))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")->orWhere('common_name', 'like', "%{$term}%")
                ->orWhere('subject_alt_names', 'like', "%{$term}%")->orWhere('issuer', 'like', "%{$term}%")))
            ->with('service:id,name')
            ->withCount('equipment')
            ->orderBy($filters['sort'] ?? 'expires_at', $filters['direction'] ?? 'asc')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Certificates/Index', [
            'context' => $this->context($client, $request),
            'certificates' => $certificates,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $status,
                'expiring' => (bool) ($filters['expiring'] ?? false),
                'sort' => $filters['sort'] ?? 'expires_at',
                'direction' => $filters['direction'] ?? 'asc',
            ],
            'statuses' => CertificateStatus::options(),
            'types' => CertificateType::options(),
            'warnDays' => (int) config('techindex.alerts.certificate_days'),
        ]);
    }

    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('contribute', $client);

        return Inertia::render('Certificates/Form', [
            'context' => $this->context($client, $request),
            'certificate' => null,
            ...$this->formOptions($client),
        ]);
    }

    public function store(CertificateRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $certificate = DB::transaction(function () use ($request, $client): Certificate {
            $certificate = new Certificate($request->safe()->except('equipment_ids'));
            $certificate->client()->associate($client);
            $certificate->save();
            $certificate->equipment()->sync($request->input('equipment_ids', []));

            return $certificate;
        });

        return redirect()->route('clients.certificates.show', [$client, $certificate])->with('success', 'Certificat enregistré.');
    }

    public function show(Request $request, Client $client, Certificate $certificate): Response
    {
        Gate::authorize('view', $certificate);

        $certificate->load(['site:id,name', 'service:id,name', 'equipment:id,client_id,name,hostname,status', 'creator:id,name', 'editor:id,name']);

        return Inertia::render('Certificates/Show', [
            'context' => $this->context($client, $request),
            'certificate' => [
                ...$certificate->toArray(),
                'type_label' => $certificate->type->label(),
                'status_label' => $certificate->status->label(),
            ],
            'history' => $this->historyFor($certificate, $request->user()),
            'warnDays' => (int) config('techindex.alerts.certificate_days'),
            'can' => [
                'update' => Gate::allows('update', $certificate),
                'delete' => Gate::allows('delete', $certificate),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, Certificate $certificate): Response
    {
        Gate::authorize('update', $certificate);

        return Inertia::render('Certificates/Form', [
            'context' => $this->context($client, $request),
            'certificate' => [...$certificate->toArray(), 'equipment_ids' => $certificate->equipment()->pluck('equipment.id')],
            ...$this->formOptions($client),
        ]);
    }

    public function update(CertificateRequest $request, Client $client, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('update', $certificate);

        DB::transaction(function () use ($request, $certificate): void {
            $certificate->fill($request->safe()->except('equipment_ids'));
            $before = $certificate->equipment()->pluck('name')->sort()->implode(', ');
            $certificate->equipment()->sync($request->input('equipment_ids', []));
            $certificate->addAuditChange('equipment', $before ?: null, $certificate->equipment()->pluck('name')->sort()->implode(', ') ?: null);
            $certificate->save();
            $certificate->flushPendingAudit();
        });

        return redirect()->route('clients.certificates.show', [$client, $certificate])->with('success', 'Certificat mis à jour.');
    }

    public function destroy(Request $request, Client $client, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('delete', $certificate);
        $this->confirmDeletion($request, $certificate->name);

        $certificate->delete();

        return redirect()->route('clients.certificates.index', $client)->with('success', 'Certificat supprimé définitivement.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Client $client): array
    {
        return [
            'types' => CertificateType::options(),
            'statuses' => CertificateStatus::options(),
            'sites' => $this->siteOptions($client),
            'services' => $this->serviceOptions($client),
            'equipment' => $this->equipmentOptions($client),
        ];
    }
}
