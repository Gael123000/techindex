<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Résultats instantanés de la barre de recherche globale (JSON).
 *
 * Mêmes règles que la page de recherche : droits appliqués dans chaque
 * requête SQL, secrets jamais interrogés.
 */
class SearchSuggestionController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim($data['q'] ?? '');

        return response()->json([
            'query' => $term,
            'groups' => mb_strlen($term) >= 2 ? $search->suggest($request->user(), $term) : [],
            'search_url' => route('search', ['q' => $term]),
        ])->header('Cache-Control', 'no-store, private');
    }
}
