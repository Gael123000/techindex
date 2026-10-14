<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomFieldType;
use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\EquipmentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Champs personnalisés typés des équipements.
 */
class CustomFieldController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-settings');

        $usage = CustomFieldValue::query()->selectRaw('custom_field_id, COUNT(*) as total')->groupBy('custom_field_id')->pluck('total', 'custom_field_id');

        return Inertia::render('Admin/CustomFields/Index', [
            'fields' => CustomField::query()
                ->with('equipmentType:id,name')
                ->orderBy('equipment_type_id')
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->map(fn (CustomField $field): array => [
                    ...$field->only(['id', 'equipment_type_id', 'key', 'label', 'options', 'is_required', 'help_text', 'sort_order']),
                    'type' => $field->type->value,
                    'type_label' => $field->type->label(),
                    'equipment_type' => $field->equipmentType?->only(['id', 'name']),
                    'values_count' => (int) ($usage[$field->id] ?? 0),
                ]),
            'types' => CustomFieldType::options(),
            'equipmentTypes' => EquipmentType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn (EquipmentType $type): array => ['value' => $type->id, 'label' => $type->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $this->validated($request);
        $data['key'] = $this->uniqueKey($data['label'], $data['equipment_type_id'] ?? null);

        CustomField::query()->create($data);

        return back()->with('success', 'Champ personnalisé ajouté.');
    }

    public function update(Request $request, CustomField $customField): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $this->validated($request);
        $hasValues = CustomFieldValue::query()->where('custom_field_id', $customField->id)->exists();

        if ($hasValues && $data['type'] !== $customField->type->value) {
            throw ValidationException::withMessages(['type' => 'Le type ne peut pas être modifié : des valeurs sont déjà saisies pour ce champ.']);
        }

        $customField->update($data);

        return back()->with('success', 'Champ personnalisé mis à jour.');
    }

    public function destroy(CustomField $customField): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $customField->delete();

        return back()->with('success', 'Champ personnalisé supprimé, ainsi que ses valeurs.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'equipment_type_id' => ['nullable', 'integer', Rule::exists('equipment_types', 'id')],
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(CustomFieldType::class)],
            'options' => ['nullable', 'array', 'max:50', 'required_if:type,select'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'is_required' => ['boolean'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,65000'],
        ], [
            'options.required_if' => 'Indiquez au moins un choix pour une liste.',
        ]);

        $data['options'] = $data['type'] === CustomFieldType::Select->value
            ? array_values(array_filter(array_map('trim', $data['options'] ?? [])))
            : null;
        $data['sort_order'] ??= 0;

        return $data;
    }

    private function uniqueKey(string $label, ?int $typeId): string
    {
        $base = Str::slug($label, '_') ?: 'champ';
        $key = $base;
        $i = 2;

        while (CustomField::query()->where('key', $key)->where('equipment_type_id', $typeId)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }
}
