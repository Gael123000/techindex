<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentCategoryController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-settings');

        return Inertia::render('Admin/DocumentCategories/Index', [
            'categories' => DocumentCategory::query()->withCount('documents')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $this->validated($request);
        $slug = Str::slug($data['name']) ?: 'categorie';
        $base = $slug;
        $i = 2;

        while (DocumentCategory::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        DocumentCategory::query()->create([...$data, 'slug' => $slug]);

        return back()->with('success', 'Catégorie ajoutée.');
    }

    public function update(Request $request, DocumentCategory $documentCategory): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $documentCategory->update($this->validated($request, $documentCategory));

        return back()->with('success', 'Catégorie mise à jour.');
    }

    public function destroy(DocumentCategory $documentCategory): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $documentCategory->delete();

        return back()->with('success', 'Catégorie supprimée : les fiches concernées sont désormais sans catégorie.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DocumentCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('document_categories', 'name')->ignore($category?->id)],
            'sort_order' => ['nullable', 'integer', 'between:0,65000'],
        ]) + ['sort_order' => 0];
    }
}
