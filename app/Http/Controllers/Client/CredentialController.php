<?php

namespace App\Http\Controllers\Client;

use App\Enums\AccessType;
use App\Enums\ClientPermission;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialRequest;
use App\Models\Client;
use App\Models\Credential;
use App\Secrets\CredentialSecrets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accès et identifiants d'un client.
 *
 * Les pages ne transmettent que des métadonnées. La valeur d'un secret
 * n'est envoyée qu'en réponse à POST .../reveal, après vérification du droit
 * "secrets", d'un déverrouillage par clé d'accès datant de moins de 5 minutes
 * et du quota de révélations ; la réponse n'est jamais mise en cache et la
 * demande est journalisée sans sa valeur. La même route sert les fiches et
 * le gestionnaire de mots de passe.
 */
class CredentialController extends Controller
{
    use InteractsWithClient;

    public function __construct(private readonly CredentialSecrets $secrets) {}

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(AccessType::class)],
            'equipment' => ['nullable', 'integer'],
            'service' => ['nullable', 'integer'],
            'internet_link' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        $credentials = $client->credentials()
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('label', 'like', "%{$term}%")->orWhere('username', 'like', "%{$term}%")
                ->orWhere('host', 'like', "%{$term}%")->orWhere('url', 'like', "%{$term}%")))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('access_type', $type))
            ->when($filters['equipment'] ?? null, fn (Builder $query, int $id) => $query->where('equipment_id', $id))
            ->when($filters['service'] ?? null, fn (Builder $query, int $id) => $query->where('service_id', $id))
            ->when($filters['internet_link'] ?? null, fn (Builder $query, int $id) => $query->where('internet_link_id', $id))
            ->with(['equipment:id,name', 'service:id,name', 'internetLink:id,name'])
            ->orderBy('label')
            ->paginate(50)
            ->withQueryString();

        $credentials->setCollection($this->presentCredentials($credentials->getCollection(), $client, $user));

        return Inertia::render('Credentials/Index', [
            'context' => $this->context($client, $request),
            'credentials' => $credentials,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'type' => $filters['type'] ?? null,
                'equipment' => $filters['equipment'] ?? null,
                'service' => $filters['service'] ?? null,
                'internet_link' => $filters['internet_link'] ?? null,
            ],
            'types' => AccessType::options(),
            'equipment' => $this->equipmentOptions($client),
            'services' => $this->serviceOptions($client),
            'internetLinks' => $this->internetLinkOptions($client),
            'can' => [
                'create' => Gate::allows('contribute', $client),
                'write_secrets' => Gate::allows('contribute', $client) && $user->hasClientPermission($client, ClientPermission::Secrets),
            ],
        ]);
    }

    public function store(CredentialRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $credential = new Credential($request->metadata());
        $credential->client()->associate($client);

        if ($request->filled('secret')) {
            Gate::authorize('writeSecret', $credential);
        }

        DB::transaction(function () use ($credential, $request): void {
            $this->secrets->apply($credential, $request->input('secret'));
            $credential->save();
        });

        return back()->with('success', 'Accès enregistré.');
    }

    public function update(CredentialRequest $request, Client $client, Credential $credential): RedirectResponse
    {
        Gate::authorize('update', $credential);

        if ($request->wantsSecretChange()) {
            Gate::authorize('writeSecret', $credential);
        }

        DB::transaction(function () use ($credential, $request): void {
            $credential->fill($request->metadata());
            $this->secrets->apply($credential, $request->input('secret'), $request->boolean('clear_secret'));
            $credential->save();
            $credential->flushPendingAudit();
        });

        return back()->with('success', 'Accès mis à jour.');
    }

    public function destroy(Client $client, Credential $credential): RedirectResponse
    {
        Gate::authorize('update', $credential);

        $credential->delete();

        return back()->with('success', 'Accès supprimé.');
    }

    /**
     * Révélation ou copie d'un secret (réponse JSON, jamais mise en cache).
     */
    public function reveal(Request $request, Client $client, Credential $credential): JsonResponse
    {
        Gate::authorize('reveal', $credential);

        $purpose = $request->validate([
            'purpose' => ['required', Rule::in([CredentialSecrets::PURPOSE_REVEAL, CredentialSecrets::PURPOSE_COPY])],
        ])['purpose'];

        return response()->json([
            'secret' => $this->secrets->reveal($credential, $request->user(), $purpose),
            // Durée d'affichage côté interface avant masquage automatique.
            'expires_in' => 30,
        ]);
    }
}
