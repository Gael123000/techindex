<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-settings');

        $usage = DB::table('taggables')->select('tag_id', DB::raw('COUNT(*) as total'))->groupBy('tag_id')->pluck('total', 'tag_id');

        return Inertia::render('Admin/Tags/Index', [
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (Tag $tag): array => [...$tag->toArray(), 'usage' => (int) ($usage[$tag->id] ?? 0)]),
            'colors' => Tag::COLORS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        Tag::query()->create($this->validated($request));

        return back()->with('success', 'Étiquette ajoutée.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $tag->update($this->validated($request, $tag));

        return back()->with('success', 'Étiquette mise à jour.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $tag->delete();

        return back()->with('success', 'Étiquette supprimée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Tag $tag = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('tags', 'name')->ignore($tag?->id)],
            'color' => ['required', Rule::in(Tag::COLORS)],
        ]);
    }
}
