<?php

namespace App\Services\Records;

use App\Enums\RecordFieldType;
use App\Models\Client;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Models\User;
use App\Rules\Cidr;
use App\Services\Documents\HtmlSanitizer;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Valeurs des champs du constructeur : validation, conversion, lecture,
 * écriture et présentation.
 *
 * Les valeurs d'un enregistrement sont dans la table de la fiche, une ligne
 * par enregistrement (record_id). Les noms de tables et de colonnes
 * proviennent uniquement des métadonnées contrôlées par RecordSchema.
 *
 * Saisie : les valeurs arrivent sous la clé « fields » (fields[<clé>]). Un
 * champ masqué par sa condition d'affichage n'est ni validé ni modifié.
 */
final class RecordValues
{
    /** Champs par type de fiche, mis en cache pour la requête. */
    private array $fields = [];

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * @return Collection<int, RecordField>
     */
    public function fields(RecordType $type): Collection
    {
        return $this->fields[$type->id] ??= $type->fields()->get();
    }

    public function forget(RecordType $type): void
    {
        unset($this->fields[$type->id]);
    }

    /**
     * Valeurs stockées des enregistrements demandés, par clé de champ.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function load(RecordType $type, array $ids): array
    {
        $fields = $this->fields($type);

        if ($ids === [] || $fields->isEmpty() || ! $type->hasDataTable()) {
            return [];
        }

        $rows = DB::table($type->table_name)
            ->whereIn('record_id', $ids)
            ->get(['record_id', ...$fields->pluck('column_name')->all()]);

        $values = [];

        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $values[(int) $row->record_id][$field->key] = $this->fromDatabase($field, $row->{$field->column_name} ?? null);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values  valeurs des champs ajoutés (clé => valeur)
     * @param  array<string, mixed>  $builtin  valeurs des champs d'origine (fiches par défaut)
     */
    public function isVisible(RecordField $field, array $values, array $builtin = []): bool
    {
        $condition = $field->visibility;

        if (! is_array($condition) || empty($condition['field'])) {
            return true;
        }

        $source = (string) $condition['field'];
        $value = array_key_exists($source, $values) ? $values[$source] : ($builtin[$source] ?? null);

        return self::conditionMatches($condition, $value);
    }

    /**
     * @param  array{field: string, operator: string, value?: mixed}  $condition
     */
    public static function conditionMatches(array $condition, mixed $value): bool
    {
        $values = array_map('strval', array_filter(is_array($value) ? $value : [$value], fn ($item): bool => $item !== null && $item !== ''));
        $expected = array_map('strval', array_filter((array) ($condition['value'] ?? []), fn ($item): bool => $item !== null && $item !== ''));
        $truthy = filter_var($value, FILTER_VALIDATE_BOOL);

        return match ($condition['operator'] ?? 'equals') {
            'filled' => $values !== [],
            'empty' => $values === [],
            'checked' => $truthy,
            'unchecked' => ! $truthy,
            'not_equals' => array_intersect($values, $expected) === [],
            default => array_intersect($values, $expected) !== [],
        };
    }

    /**
     * Règles de validation des champs visibles.
     *
     * @param  array<string, mixed>  $input  saisie complète (fields + champs d'origine)
     * @return array<string, mixed>
     */
    public function rules(RecordType $type, Client $client, array $input, ?int $recordId = null): array
    {
        $values = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $rules = ['fields' => ['nullable', 'array']];

        foreach ($this->fields($type) as $field) {
            if (! $this->isVisible($field, $values, $input)) {
                continue;
            }

            $rules['fields.'.$field->key] = $this->fieldRules($type, $field, $client, $recordId);

            if ($field->type === RecordFieldType::MultiSelect) {
                $rules['fields.'.$field->key.'.*'] = ['string', Rule::in($field->choices())];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(RecordType $type): array
    {
        return $this->fields($type)
            ->mapWithKeys(fn (RecordField $field): array => ['fields.'.$field->key => mb_strtolower($field->label)])
            ->all();
    }

    /**
     * @return list<mixed>
     */
    private function fieldRules(RecordType $type, RecordField $field, Client $client, ?int $recordId): array
    {
        $required = $field->is_required && $field->type !== RecordFieldType::Boolean;
        $rules = [$required ? 'required' : 'nullable'];
        $min = $field->option('min');
        $max = $field->option('max');
        $bounds = array_values(array_filter([
            is_numeric($min) ? 'min:'.$min : null,
            is_numeric($max) ? 'max:'.$max : null,
        ]));

        $rules = [...$rules, ...match ($field->type) {
            RecordFieldType::Text => ['string', 'max:'.min(255, (int) ($field->option('max_length') ?: 255))],
            RecordFieldType::Textarea => ['string', 'max:20000'],
            RecordFieldType::RichText => ['string', 'max:200000'],
            RecordFieldType::Integer => ['integer', 'between:-9007199254740991,9007199254740991', ...$bounds],
            RecordFieldType::Decimal => ['numeric', 'decimal:0,4', 'between:-9999999999999999,9999999999999999', ...$bounds],
            RecordFieldType::Currency => ['numeric', 'decimal:0,2', 'between:-999999999999,999999999999', ...$bounds],
            RecordFieldType::Date => ['date_format:Y-m-d'],
            RecordFieldType::DateTime => ['date'],
            RecordFieldType::Boolean => ['boolean'],
            RecordFieldType::Select => ['string', Rule::in($field->choices())],
            RecordFieldType::MultiSelect => ['array', 'max:50'],
            RecordFieldType::Email => ['string', 'email', 'max:255'],
            RecordFieldType::Url => ['string', 'max:500', 'url:http,https'],
            RecordFieldType::Phone => ['string', 'max:50', 'regex:/^[0-9+().\s\/-]{2,50}$/'],
            RecordFieldType::Ip => ['ip'],
            RecordFieldType::Cidr => ['string', 'max:50', new Cidr],
            RecordFieldType::Reference => ['integer', RecordReferences::existsRule((string) $field->option('target'), $client)],
            RecordFieldType::User => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        }];

        if ($field->is_unique && $type->entity !== 'client') {
            $rules[] = $this->uniqueRule($type, $field, $client, $recordId);
        }

        return $rules;
    }

    /**
     * Unicité de la valeur parmi les fiches du même client.
     */
    private function uniqueRule(RecordType $type, RecordField $field, Client $client, ?int $recordId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type, $field, $client, $recordId): void {
            if ($value === null || $value === '' || is_array($value) || ! $type->hasDataTable()) {
                return;
            }

            $parent = $type->is_system ? (string) SystemRecordTypes::tableFor((string) $type->entity) : 'custom_records';

            $exists = DB::table($type->table_name.' as data')
                ->join($parent.' as owner', 'owner.id', '=', 'data.record_id')
                ->where('owner.client_id', $client->id)
                ->where('data.'.$field->column_name, $this->toDatabase($field, $this->normalize($field, $value)))
                ->when($recordId !== null, fn ($query) => $query->where('data.record_id', '!=', $recordId))
                ->exists();

            if ($exists) {
                $fail('Cette valeur est déjà utilisée par une autre fiche de ce client.');
            }
        };
    }

    /**
     * Enregistre les champs visibles et renvoie les changements lisibles
     * (libellé => ancienne et nouvelle valeur affichées).
     *
     * @param  array<string, mixed>  $input  saisie validée complète
     * @return array<string, array{old: string|null, new: string|null}>
     */
    public function save(RecordType $type, int $recordId, int $clientId, array $input, bool $isNew): array
    {
        $fields = $this->fields($type);

        if ($fields->isEmpty() || ! $type->hasDataTable()) {
            return [];
        }

        $values = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $current = $isNew ? [] : ($this->load($type, [$recordId])[$recordId] ?? []);
        $row = [];
        $changes = [];
        $references = [];

        foreach ($fields as $field) {
            if (! $this->isVisible($field, $values, $input) || ! array_key_exists($field->key, $values)) {
                continue;
            }

            $new = $this->normalize($field, $values[$field->key]);
            $row[$field->column_name] = $this->toDatabase($field, $new);

            $old = $current[$field->key] ?? null;

            if (! $isNew && $this->comparable($old) !== $this->comparable($new)) {
                $changes[$field->label] = ['old' => $old, 'new' => $new, 'field' => $field];
                $this->collectReference($field, [$old, $new], $references);
            }
        }

        if ($row !== []) {
            DB::table($type->table_name)->updateOrInsert(['record_id' => $recordId], $row);
        }

        $labels = $this->referenceLabels($references, $clientId);

        return array_map(fn (array $change): array => [
            'old' => $this->display($change['field'], $change['old'], $labels, 500),
            'new' => $this->display($change['field'], $change['new'], $labels, 500),
        ], $changes);
    }

    /**
     * Saisie convertie dans sa forme logique (avant écriture).
     */
    public function normalize(RecordField $field, mixed $raw): mixed
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return $field->type === RecordFieldType::Boolean ? false : null;
        }

        return match ($field->type) {
            RecordFieldType::Boolean => filter_var($raw, FILTER_VALIDATE_BOOL),
            RecordFieldType::Integer, RecordFieldType::Reference, RecordFieldType::User => (int) $raw,
            RecordFieldType::Decimal => $this->decimal($raw, 4),
            RecordFieldType::Currency => $this->decimal($raw, 2),
            RecordFieldType::DateTime => Carbon::parse((string) $raw, (string) config('app.display_timezone'))->utc()->format('Y-m-d H:i:s'),
            RecordFieldType::MultiSelect => $this->multiple($field, $raw),
            RecordFieldType::RichText => ($html = $this->sanitizer->sanitize((string) $raw)) === '' ? null : $html,
            RecordFieldType::Textarea => trim((string) $raw) === '' ? null : rtrim((string) $raw),
            default => trim((string) $raw) === '' ? null : trim((string) $raw),
        };
    }

    private function toDatabase(RecordField $field, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $field->type === RecordFieldType::MultiSelect => json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE),
            $field->type === RecordFieldType::Boolean => $value ? 1 : 0,
            default => $value,
        };
    }

    private function fromDatabase(RecordField $field, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($field->type) {
            RecordFieldType::Boolean => (bool) $raw,
            RecordFieldType::Integer, RecordFieldType::Reference, RecordFieldType::User => (int) $raw,
            RecordFieldType::Decimal => $this->decimal($raw, 4),
            RecordFieldType::Currency => $this->decimal($raw, 2),
            RecordFieldType::Date => substr((string) $raw, 0, 10),
            RecordFieldType::DateTime => substr((string) $raw, 0, 19),
            RecordFieldType::MultiSelect => array_values(array_map('strval', (array) (json_decode((string) $raw, true) ?? []))),
            default => (string) $raw,
        };
    }

    /**
     * Valeur pour un champ de formulaire (date et heure dans le fuseau d'affichage).
     */
    public function toInput(RecordField $field, mixed $value): mixed
    {
        if ($value === null) {
            return match ($field->type) {
                RecordFieldType::Boolean => false,
                RecordFieldType::MultiSelect => [],
                default => null,
            };
        }

        return $field->type === RecordFieldType::DateTime
            ? Carbon::parse((string) $value, 'UTC')->timezone((string) config('app.display_timezone'))->format('Y-m-d\TH:i')
            : $value;
    }

    /**
     * Valeur affichée (fiche, liste, historique, export).
     *
     * @param  array<string, array<int, string>>  $references  libellés par cible puis identifiant
     */
    public function display(RecordField $field, mixed $value, array $references = [], ?int $limit = null): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $unit = trim((string) $field->option('unit', ''));
        $suffix = $unit === '' ? '' : ' '.$unit;
        $timezone = (string) config('app.display_timezone');

        $text = match ($field->type) {
            RecordFieldType::Boolean => $value ? 'Oui' : 'Non',
            RecordFieldType::Integer => number_format((int) $value, 0, ',', ' ').$suffix,
            RecordFieldType::Decimal => rtrim(rtrim(number_format((float) $value, 4, ',', ' '), '0'), ',').$suffix,
            RecordFieldType::Currency => number_format((float) $value, 2, ',', ' ').' €',
            RecordFieldType::Date => Carbon::parse((string) $value)->format('d/m/Y'),
            RecordFieldType::DateTime => Carbon::parse((string) $value, 'UTC')->timezone($timezone)->format('d/m/Y H:i'),
            RecordFieldType::MultiSelect => implode(', ', (array) $value),
            RecordFieldType::RichText => $this->sanitizer->toPlainText((string) $value),
            RecordFieldType::Reference => $references[(string) $field->option('target')][(int) $value] ?? '#'.$value,
            RecordFieldType::User => $references['user'][(int) $value] ?? '#'.$value,
            default => (string) $value,
        };

        return $limit !== null ? Str::limit($text, $limit) : $text;
    }

    /**
     * Libellés des liens et utilisateurs désignés par un ensemble de valeurs.
     *
     * @param  list<array<string, mixed>>  $rows  valeurs par clé de champ
     * @return array<string, array<int, string>>
     */
    public function referenceLabelsFor(RecordType $type, array $rows, int $clientId): array
    {
        $references = [];

        foreach ($this->fields($type) as $field) {
            $this->collectReference($field, array_column($rows, $field->key), $references);
        }

        return $this->referenceLabels($references, $clientId);
    }

    /**
     * @param  list<mixed>  $values
     * @param  array<string, list<int>>  $references
     */
    private function collectReference(RecordField $field, array $values, array &$references): void
    {
        $key = match ($field->type) {
            RecordFieldType::Reference => (string) $field->option('target'),
            RecordFieldType::User => 'user',
            default => null,
        };

        if ($key === null) {
            return;
        }

        foreach ($values as $value) {
            if (is_int($value) || ctype_digit((string) $value)) {
                $references[$key][] = (int) $value;
            }
        }
    }

    /**
     * @param  array<string, list<int>>  $references
     * @return array<string, array<int, string>>
     */
    private function referenceLabels(array $references, int $clientId): array
    {
        $labels = [];

        foreach ($references as $target => $ids) {
            $labels[$target] = $target === 'user'
                ? User::query()->whereKey(array_unique($ids))->pluck('name', 'id')->all()
                : RecordReferences::labels($target, $clientId, $ids);
        }

        return $labels;
    }

    /**
     * Valeurs présentées par section pour la page d'une fiche.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $builtin
     * @return list<array<string, mixed>>
     */
    public function present(RecordType $type, int $clientId, array $values, array $builtin = []): array
    {
        $labels = $this->referenceLabelsFor($type, [$values], $clientId);
        $fields = $this->fields($type)->filter(fn (RecordField $field): bool => $this->isVisible($field, $values, $builtin));
        $sections = [];

        foreach ($type->sectionList() as $section) {
            $items = $fields->filter(fn (RecordField $field): bool => ($field->section ?? $type->sectionList()[0]['key']) === $section['key'])
                ->map(function (RecordField $field) use ($values, $labels, $clientId): array {
                    $value = $values[$field->key] ?? null;

                    return [
                        'key' => $field->key,
                        'label' => $field->label,
                        'type' => $field->type->value,
                        'width' => $field->width,
                        'display' => $this->display($field, $value, $labels),
                        'html' => $field->type === RecordFieldType::RichText && $value !== null ? $this->sanitizer->sanitize((string) $value) : null,
                        'url' => $this->linkFor($field, $value, $clientId),
                    ];
                })
                ->values()
                ->all();

            if ($items !== []) {
                $sections[] = [...$section, 'fields' => $items];
            }
        }

        // Champs rangés dans une section supprimée : regroupés à la fin.
        $known = array_column($type->sectionList(), 'key');
        $orphans = $fields->reject(fn (RecordField $field): bool => in_array($field->section ?? $known[0], $known, true));

        if ($orphans->isNotEmpty()) {
            $sections[] = [
                'key' => '_autres', 'title' => 'Autres informations', 'description' => null, 'columns' => 2,
                'fields' => $orphans->map(fn (RecordField $field): array => [
                    'key' => $field->key, 'label' => $field->label, 'type' => $field->type->value, 'width' => $field->width,
                    'display' => $this->display($field, $values[$field->key] ?? null, $labels), 'html' => null,
                    'url' => $this->linkFor($field, $values[$field->key] ?? null, $clientId),
                ])->values()->all(),
            ];
        }

        return $sections;
    }

    private function linkFor(RecordField $field, mixed $value, int $clientId): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field->type) {
            RecordFieldType::Reference => RecordReferences::url((string) $field->option('target'), $clientId, (int) $value),
            RecordFieldType::Url => str_starts_with((string) $value, 'http') ? (string) $value : null,
            RecordFieldType::Email => 'mailto:'.$value,
            RecordFieldType::Phone => 'tel:'.preg_replace('/[^\d+]/', '', (string) $value),
            default => null,
        };
    }

    /**
     * Définition d'un formulaire : sections, champs et listes d'options.
     *
     * @return array<string, mixed>
     */
    public function formDefinition(RecordType $type, Client $client): array
    {
        $fields = $this->fields($type);
        $references = [];

        foreach ($fields as $field) {
            if ($field->type === RecordFieldType::Reference) {
                $target = (string) $field->option('target');
                $references[$target] ??= RecordReferences::options($target, $client);
            }
        }

        return [
            'sections' => $type->sectionList(),
            'fields' => $fields->map(fn (RecordField $field): array => self::fieldDefinition($field))->values()->all(),
            'references' => (object) $references,
            'users' => $fields->contains(fn (RecordField $field): bool => $field->type === RecordFieldType::User)
                ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldDefinition(RecordField $field): array
    {
        return [
            'id' => $field->id,
            'key' => $field->key,
            'column_name' => $field->column_name,
            'label' => $field->label,
            'type' => $field->type->value,
            'type_label' => $field->type->label(),
            'options' => (object) ($field->options ?? []),
            'help_text' => $field->help_text,
            'is_required' => $field->is_required,
            'is_unique' => $field->is_unique,
            'is_title' => $field->is_title,
            'in_list' => $field->in_list,
            'is_searchable' => $field->is_searchable,
            'section' => $field->section,
            'width' => $field->width,
            'sort_order' => $field->sort_order,
            'visibility' => $field->visibility,
        ];
    }

    /**
     * Valeurs initiales d'un formulaire (fiche existante ou valeurs par défaut).
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    public function formValues(RecordType $type, ?array $values): array
    {
        $result = [];

        foreach ($this->fields($type) as $field) {
            $result[$field->key] = $values === null
                ? $this->defaultValue($field)
                : $this->toInput($field, $values[$field->key] ?? null);
        }

        return $result;
    }

    private function defaultValue(RecordField $field): mixed
    {
        $default = $field->option('default');

        return match ($field->type) {
            RecordFieldType::Boolean => (bool) $default,
            RecordFieldType::MultiSelect => [],
            RecordFieldType::Date => $default === 'today' ? now((string) config('app.display_timezone'))->format('Y-m-d') : null,
            default => $default === null || $default === '' ? null : $default,
        };
    }

    /**
     * Titre d'un enregistrement : valeur du champ titre.
     *
     * @param  array<string, mixed>  $values
     */
    public function title(RecordType $type, array $values): string
    {
        $field = $this->fields($type)->firstWhere('is_title', true);
        $title = $field === null ? null : $this->display($field, $values[$field->key] ?? null);

        return Str::limit(trim((string) $title) === '' ? 'Sans titre' : (string) $title, 250);
    }

    /**
     * Texte de recherche : valeurs des champs marqués « inclus dans la recherche ».
     *
     * @param  array<string, mixed>  $values
     */
    public function searchText(RecordType $type, int $clientId, array $values): ?string
    {
        $labels = $this->referenceLabelsFor($type, [$values], $clientId);
        $parts = $this->fields($type)
            ->filter(fn (RecordField $field): bool => $field->is_searchable && ! $field->is_title)
            ->map(fn (RecordField $field): ?string => $this->display($field, $values[$field->key] ?? null, $labels))
            ->filter()
            ->implode(' ');

        return $parts === '' ? null : mb_substr($parts, 0, 60000);
    }

    private function decimal(mixed $value, int $scale): string
    {
        return number_format((float) str_replace(',', '.', (string) $value), $scale, '.', '');
    }

    /**
     * @return list<string>|null
     */
    private function multiple(RecordField $field, mixed $raw): ?array
    {
        $selected = array_map('strval', (array) $raw);
        $ordered = array_values(array_filter($field->choices(), fn (string $choice): bool => in_array($choice, $selected, true)));

        return $ordered === [] ? null : $ordered;
    }

    private function comparable(mixed $value): mixed
    {
        return is_array($value) ? json_encode($value) : ($value === null ? null : (string) (is_bool($value) ? (int) $value : $value));
    }
}
