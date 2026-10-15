<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EquipmentType;
use App\Services\Records\SystemRecordTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentTypeController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-settings');

        // Champs ajoutés à la fiche « Équipements » affichés seulement pour certains types.
        $fieldCounts = [];

        foreach (SystemRecordTypes::type('equipment')?->fields()->get() ?? [] as $field) {
            if (($field->visibility['field'] ?? null) === 'equipment_type_id' && ($field->visibility['operator'] ?? null) === 'equals') {
                foreach ((array) ($field->visibility['value'] ?? []) as $typeId) {
                    $fieldCounts[(int) $typeId] = ($fieldCounts[(int) $typeId] ?? 0) + 1;
                }
            }
        }

        return Inertia::render('Admin/EquipmentTypes/Index', [
            'types' => EquipmentType::query()->withCount('equipment')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (EquipmentType $type): array => [...$type->toArray(), 'fields_count' => $fieldCounts[$type->id] ?? 0]),
            'icons' => EquipmentType::ICONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $this->validated($request);
        EquipmentType::query()->create([...$data, 'slug' => $this->uniqueSlug($data['name'])]);

        return back()->with('success', 'Type d\'équipement ajouté.');
    }

    public function update(Request $request, EquipmentType $equipmentType): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $equipmentType->update($this->validated($request, $equipmentType));

        return back()->with('success', 'Type d\'équipement mis à jour.');
    }

    public function destroy(EquipmentType $equipmentType): RedirectResponse
    {
        Gate::authorize('manage-settings');

        if ($equipmentType->equipment()->exists()) {
            return back()->with('error', 'Ce type est utilisé par des équipements : modifiez-les avant de le supprimer.');
        }

        $equipmentType->delete();

        return back()->with('success', 'Type d\'équipement supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?EquipmentType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('equipment_types', 'name')->ignore($type?->id)],
            'icon' => ['required', Rule::in(EquipmentType::ICONS)],
            'sort_order' => ['nullable', 'integer', 'between:0,65000'],
        ]) + ['sort_order' => 0];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'type';
        $slug = $base;
        $i = 2;

        while (EquipmentType::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
