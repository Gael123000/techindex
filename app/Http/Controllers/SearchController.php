<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Search\GlobalSearch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(array_keys(GlobalSearch::TYPES))],
            'client' => ['nullable', 'integer'],
            'site' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $term = trim($filters['q'] ?? '');
        $client = $filters['client'] ?? null;

        // Un client inaccessible passé dans l'URL est simplement ignoré : les
        // requêtes restent limitées aux clients autorisés.
        $results = mb_strlen($term) >= 2
            ? $search->search($user, $term, [
                'client' => $client,
                'site' => $filters['site'] ?? null,
                'status' => $filters['status'] ?? null,
            ], $filters['type'] ?? null, (int) ($filters['page'] ?? 1))
            : null;

        $clients = Client::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']);
        $sites = $client !== null && $clients->contains('id', $client)
            ? Client::query()->find($client)?->sites()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($site): array => ['value' => $site->id, 'label' => $site->name])
            : collect();

        return Inertia::render('Search/Index', [
            'query' => $term,
            'filters' => [
                'type' => $filters['type'] ?? null,
                'client' => $client,
                'site' => $filters['site'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'results' => $results,
            'options' => [
                'types' => collect(GlobalSearch::TYPES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
                'clients' => $clients->map(fn (Client $item): array => ['value' => $item->id, 'label' => $item->name]),
                'sites' => $sites,
            ],
        ]);
    }
}
