<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\HasRecordExtensions;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Models\Client;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    use HasRecordExtensions, InteractsWithClient;

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'site' => ['nullable', 'integer'],
            'technical' => ['nullable', 'boolean'],
        ]);

        $contacts = $client->contacts()
            ->with('site:id,name')
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('last_name', 'like', "%{$term}%")->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")->orWhere('job_title', 'like', "%{$term}%")))
            ->when($filters['site'] ?? null, fn (Builder $query, int $site) => $query->where('site_id', $site))
            ->when($filters['technical'] ?? false, fn (Builder $query) => $query->where('is_technical', true))
            ->orderByDesc('is_primary')
            ->orderBy('last_name')
            ->paginate(50)
            ->withQueryString();

        // Champs ajoutés avec le constructeur : valeurs de saisie et d'affichage de chaque contact.
        $extension = $this->recordExtensions()->definition('contact', $client);

        if ($extension !== null) {
            $values = $this->recordExtensions()->valuesFor('contact', $contacts->getCollection()->pluck('id')->all());
            $displays = $this->recordExtensions()->presentMany('contact', $client->id, $contacts->getCollection());
            $contacts->setCollection($contacts->getCollection()->map(fn (Contact $contact): array => [
                ...$contact->toArray(),
                'fields' => (object) ($values[$contact->id] ?? []),
                'extra' => $displays[$contact->id] ?? [],
            ]));
        }

        return Inertia::render('Contacts/Index', [
            'context' => $this->context($client, $request),
            'contacts' => $contacts,
            'extension' => $extension,
            'filters' => ['q' => $filters['q'] ?? '', 'site' => $filters['site'] ?? null, 'technical' => (bool) ($filters['technical'] ?? false)],
            'sites' => $this->siteOptions($client),
        ]);
    }

    public function store(ContactRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        DB::transaction(function () use ($request, $client): void {
            $contact = new Contact($request->safe()->except('fields'));
            $contact->client()->associate($client);
            $contact->save();
            $this->saveExtension('contact', $contact, $client, $request->validated(), true);
        });

        return back()->with('success', 'Contact ajouté.');
    }

    public function update(ContactRequest $request, Client $client, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        DB::transaction(function () use ($request, $client, $contact): void {
            $contact->fill($request->safe()->except('fields'));
            $this->saveExtension('contact', $contact, $client, $request->validated(), false);
            $contact->save();
            $contact->flushPendingAudit();
        });

        return back()->with('success', 'Contact mis à jour.');
    }

    public function destroy(Client $client, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $contact->delete();

        return back()->with('success', 'Contact supprimé.');
    }
}
