<?php

namespace App\Http\Controllers\Client;

use App\Enums\RecordStatus;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\SiteRequest;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    use InteractsWithClient;

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);
        $status = $filters['status'] ?? 'active';

        return Inertia::render('Sites/Index', [
            'context' => $this->context($client, $request),
            'sites' => $client->sites()
                ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
                ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$term}%")->orWhere('city', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
                ->withCount(['equipment', 'contacts'])
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $status],
        ]);
    }

    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('contribute', $client);

        return Inertia::render('Sites/Form', [
            'context' => $this->context($client, $request),
            'site' => null,
        ]);
    }

    public function store(SiteRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $site = new Site($request->validated());
        $site->client()->associate($client);
        $site->status = RecordStatus::Active;
        $site->save();

        return redirect()->route('clients.sites.show', [$client, $site])->with('success', 'Site créé.');
    }

    public function show(Request $request, Client $client, Site $site): Response
    {
        Gate::authorize('view', $site);

        $user = $request->user();

        return Inertia::render('Sites/Show', [
            'context' => $this->context($client, $request),
            'site' => $site,
            'contacts' => $site->contacts()->orderBy('last_name')->get(),
            'equipment' => $site->equipment()->with(['type:id,name,icon', 'interfaces' => fn ($query) => $query->where('is_primary', true)])
                ->where('status', '!=', 'archived')->orderBy('name')->limit(100)
                ->get(['id', 'client_id', 'site_id', 'equipment_type_id', 'name', 'hostname', 'model', 'status']),
            'subnets' => $site->subnets()->with('vlan:id,number,name')->orderBy('cidr')->get(),
            'services' => $site->services()->orderBy('name')->get(['id', 'client_id', 'site_id', 'name', 'category', 'provider', 'status']),
            'history' => $this->historyFor($site, $user),
            'can' => [
                'update' => Gate::allows('update', $site),
                'delete' => Gate::allows('delete', $site),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, Site $site): Response
    {
        Gate::authorize('update', $site);

        return Inertia::render('Sites/Form', [
            'context' => $this->context($client, $request),
            'site' => $site,
        ]);
    }

    public function update(SiteRequest $request, Client $client, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $site->update($request->validated());

        return redirect()->route('clients.sites.show', [$client, $site])->with('success', 'Site mis à jour.');
    }

    public function archive(Client $client, Site $site): RedirectResponse
    {
        Gate::authorize('archive', $site);

        $site->forceFill(['status' => RecordStatus::Archived, 'archived_at' => now()])->save();

        return back()->with('success', 'Site archivé.');
    }

    public function restore(Client $client, Site $site): RedirectResponse
    {
        Gate::authorize('archive', $site);

        $site->forceFill(['status' => RecordStatus::Active, 'archived_at' => null])->save();

        return back()->with('success', 'Site restauré.');
    }

    public function destroy(Request $request, Client $client, Site $site): RedirectResponse
    {
        Gate::authorize('delete', $site);
        $this->confirmDeletion($request, $site->name);

        $site->delete();

        return redirect()->route('clients.sites.index', $client)->with('success', 'Site supprimé. Les équipements et contacts associés ont été conservés sans site.');
    }
}
