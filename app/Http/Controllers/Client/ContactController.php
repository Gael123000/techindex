<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Models\Client;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    use InteractsWithClient;

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'site' => ['nullable', 'integer'],
            'technical' => ['nullable', 'boolean'],
        ]);

        return Inertia::render('Contacts/Index', [
            'context' => $this->context($client, $request),
            'contacts' => $client->contacts()
                ->with('site:id,name')
                ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                    ->where('last_name', 'like', "%{$term}%")->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")->orWhere('job_title', 'like', "%{$term}%")))
                ->when($filters['site'] ?? null, fn (Builder $query, int $site) => $query->where('site_id', $site))
                ->when($filters['technical'] ?? false, fn (Builder $query) => $query->where('is_technical', true))
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->paginate(50)
                ->withQueryString(),
            'filters' => ['q' => $filters['q'] ?? '', 'site' => $filters['site'] ?? null, 'technical' => (bool) ($filters['technical'] ?? false)],
            'sites' => $this->siteOptions($client),
        ]);
    }

    public function store(ContactRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $contact = new Contact($request->validated());
        $contact->client()->associate($client);
        $contact->save();

        return back()->with('success', 'Contact ajouté.');
    }

    public function update(ContactRequest $request, Client $client, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $contact->update($request->validated());

        return back()->with('success', 'Contact mis à jour.');
    }

    public function destroy(Client $client, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $contact->delete();

        return back()->with('success', 'Contact supprimé.');
    }
}
