<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suggestions d'étiquettes (noms uniquement) pour les champs de saisie.
 */
class TagSuggestionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = (string) $request->validate(['q' => ['nullable', 'string', 'max:60']])['q'];

        return response()->json(
            Tag::query()
                ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.$term.'%'))
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'color']),
        );
    }
}
