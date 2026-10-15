<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecordFieldType;
use App\Http\Controllers\Controller;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Services\Records\RecordReferences;
use App\Services\Records\RecordSchema;
use App\Services\Records\RecordTypeManager;
use App\Services\Records\RecordValues;
use App\Services\Records\SystemRecordTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Constructeur de fiches : liste, création, réglages, disposition et suppression.
 */
class RecordTypeController extends Controller
{
    public function __construct(private readonly RecordTypeManager $manager) {}

    public function index(): Response
    {
        Gate::authorize('manage-settings');

        $fieldCounts = RecordField::query()->selectRaw('record_type_id, COUNT(*) as total')->groupBy('record_type_id')->pluck('total', 'record_type_id');
        $recordCounts = DB::table('custom_records')->selectRaw('record_type_id, COUNT(*) as total')->groupBy('record_type_id')->pluck('total', 'record_type_id');
        $definitions = SystemRecordTypes::definitions();

        return Inertia::render('Admin/RecordTypes/Index', [
            'types' => RecordType::query()->orderByDesc('is_system')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (RecordType $type): array => [
                    ...$type->only(['id', 'key', 'name', 'plural_name', 'description', 'icon', 'is_system', 'is_active', 'table_name', 'tab_after']),
                    'fields_count' => (int) ($fieldCounts[$type->id] ?? 0),
                    'builtin_count' => $type->is_system ? count($definitions[$type->entity]['builtin'] ?? []) : 0,
                    'records_count' => $type->is_system ? null : (int) ($recordCounts[$type->id] ?? 0),
                ]),
            'icons' => RecordType::ICONS,
            'tabs' => self::tabOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'plural_name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['required', Rule::in(RecordType::ICONS)],
            'table' => ['required', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! RecordSchema::isValidIdentifier($value) || str_starts_with($value, 'ext_')) {
                    $fail('Nom de table invalide : lettres minuscules, chiffres et « _ », commençant par une lettre (2 à 40 caractères).');

                    return;
                }

                $key = str_replace('_', '-', $value);

                if (Schema::hasTable(RecordSchema::TABLE_PREFIX.$value) || RecordType::query()->where('table_name', RecordSchema::TABLE_PREFIX.$value)->orWhere('key', $key)->exists()) {
                    $fail('Une fiche utilise déjà ce nom de table.');
                }
            }],
            'title_label' => ['nullable', 'string', 'max:120'],
            'tab_after' => ['nullable', Rule::in(array_keys(RecordType::TABS))],
            'settings' => ['nullable', 'array'],
            'settings.has_site' => ['boolean'],
            'settings.has_tags' => ['boolean'],
            'settings.has_attachments' => ['boolean'],
        ]);

        $type = $this->manager->createType([
            ...$data,
            'tab_after' => $data['tab_after'] ?? 'documents',
            'settings' => $this->settings($data['settings'] ?? []),
        ]);

        return redirect()->route('admin.record-types.show', $type)->with('success', 'Fiche créée : ajoutez maintenant ses champs.');
    }

    public function show(RecordType $recordType, RecordSchema $schema, RecordValues $values): Response
    {
        Gate::authorize('manage-settings');

        $fields = $values->fields($recordType);
        $definition = $recordType->is_system ? SystemRecordTypes::definitions()[$recordType->entity] ?? null : null;

        return Inertia::render('Admin/RecordTypes/Builder', [
            'recordType' => [
                ...$recordType->only(['id', 'key', 'name', 'plural_name', 'description', 'icon', 'is_system', 'entity', 'is_active', 'table_name', 'tab_after', 'sort_order']),
                'sections' => $recordType->sectionList(),
                'settings' => (object) ($recordType->settings ?? []),
                'records_count' => $recordType->is_system ? null : $recordType->records()->count(),
                'updated_at' => $recordType->updated_at?->toIso8601String(),
            ],
            'fields' => $fields->map(fn (RecordField $field): array => RecordValues::fieldDefinition($field))->values(),
            'builtin' => $definition['builtin'] ?? [],
            'conditionSources' => $recordType->is_system ? (object) SystemRecordTypes::conditionSources((string) $recordType->entity) : (object) [],
            'fieldTypes' => RecordFieldType::definitions(),
            'referenceTargets' => RecordReferences::targets(),
            'reservedColumns' => RecordSchema::RESERVED_COLUMNS,
            'database' => $schema->describe($recordType),
            'icons' => RecordType::ICONS,
            'tabs' => self::tabOptions(),
        ]);
    }

    public function update(Request $request, RecordType $recordType): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'plural_name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['required', Rule::in(RecordType::ICONS)],
            'tab_after' => ['nullable', Rule::in(array_keys(RecordType::TABS))],
            'is_active' => ['boolean'],
            'settings' => ['nullable', 'array'],
            'settings.has_site' => ['boolean'],
            'settings.has_tags' => ['boolean'],
            'settings.has_attachments' => ['boolean'],
            'settings.sort_field' => ['nullable', 'string', 'max:60'],
            'settings.sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];

        $data = $request->validate($rules);

        // Fiche par défaut : seul le nom de la section d'ajouts est personnalisable, pas l'identité de la fiche.
        if ($recordType->is_system) {
            return back()->with('error', 'Les réglages d\'une fiche par défaut ne sont pas modifiables : ajoutez-lui des champs et des sections.');
        }

        $sortField = $data['settings']['sort_field'] ?? null;

        if ($sortField !== null && ! in_array($sortField, ['title', 'updated_at', 'created_at'], true)
            && ! $recordType->fields()->where('key', $sortField)->where('in_list', true)->exists()) {
            return back()->withErrors(['settings.sort_field' => 'Le tri doit porter sur une colonne affichée dans la liste.']);
        }

        $recordType->update([
            'name' => $data['name'],
            'plural_name' => $data['plural_name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'],
            'tab_after' => $data['tab_after'] ?? 'documents',
            'is_active' => (bool) ($data['is_active'] ?? true),
            'settings' => [
                ...$this->settings($data['settings'] ?? []),
                'sort_field' => $sortField,
                'sort_direction' => $data['settings']['sort_direction'] ?? 'asc',
            ],
        ]);

        return back()->with('success', 'Réglages de la fiche enregistrés.');
    }

    public function layout(Request $request, RecordType $recordType): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1', 'max:30'],
            'sections.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'sections.*.title' => ['required', 'string', 'max:120'],
            'sections.*.description' => ['nullable', 'string', 'max:500'],
            'sections.*.columns' => ['required', 'integer', 'between:1,3'],
            'fields' => ['present', 'array', 'max:500'],
            'fields.*.id' => ['required', 'integer', Rule::exists('record_fields', 'id')->where('record_type_id', $recordType->id)],
            'fields.*.section' => ['required', 'string', 'max:40'],
            'fields.*.width' => ['required', Rule::in(RecordField::WIDTHS)],
        ], [
            'sections.*.title.required' => 'Chaque section doit avoir un titre.',
        ]);

        $this->manager->saveLayout($recordType, $data['sections'], $data['fields']);

        // Enregistrement automatique à chaque déplacement : l'état s'affiche dans le constructeur, sans message.
        return back();
    }

    public function destroy(Request $request, RecordType $recordType): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $request->validate([
            'confirmation' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($recordType): void {
                if (trim((string) $value) !== $recordType->name) {
                    $fail('Le texte de confirmation ne correspond pas.');
                }
            }],
        ]);

        $this->manager->deleteType($recordType);

        return redirect()->route('admin.record-types.index')->with('success', 'Fiche « '.$recordType->name.' » supprimée, avec sa table et ses données.');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, bool>
     */
    private function settings(array $settings): array
    {
        return [
            'has_site' => (bool) ($settings['has_site'] ?? true),
            'has_tags' => (bool) ($settings['has_tags'] ?? true),
            'has_attachments' => (bool) ($settings['has_attachments'] ?? true),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function tabOptions(): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys(RecordType::TABS),
            RecordType::TABS,
        );
    }
}
