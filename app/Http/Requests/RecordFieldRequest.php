<?php

namespace App\Http\Requests;

use App\Enums\RecordFieldType;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Rules\NotSecretField;
use App\Services\Records\RecordReferences;
use App\Services\Records\RecordSchema;
use App\Services\Records\SystemRecordTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ajout ou modification d'un champ dans le constructeur de fiches.
 */
class RecordFieldRequest extends FormRequest
{
    public const OPERATORS = ['equals', 'not_equals', 'filled', 'empty', 'checked', 'unchecked'];

    public function authorize(): bool
    {
        // Contrôle effectué dans le contrôleur (droit « manage-settings »).
        return true;
    }

    private function recordType(): RecordType
    {
        /** @var RecordType $type */
        $type = $this->route('recordType');

        return $type;
    }

    private function field(): ?RecordField
    {
        $field = $this->route('field');

        return $field instanceof RecordField ? $field : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120', new NotSecretField],
            'type' => ['required', Rule::enum(RecordFieldType::class)],
            // Nom technique (colonne SQL), choisi à la création puis figé.
            'key' => $this->field() === null
                ? ['required', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! RecordSchema::isValidColumn($value)) {
                        $fail('Nom technique invalide : lettres minuscules, chiffres et « _ », commençant par une lettre (2 à 40 caractères), hors noms réservés.');
                    }
                }]
                : ['prohibited'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'is_required' => ['boolean'],
            'is_unique' => ['boolean'],
            'in_list' => ['boolean'],
            'is_searchable' => ['boolean'],
            'section' => ['nullable', 'string', 'max:40'],
            'width' => ['nullable', Rule::in(RecordField::WIDTHS)],
            'options' => ['nullable', 'array'],
            // Choix et cible ne sont lus que pour les types concernés.
            'options.choices' => ['exclude_unless:type,select,multiselect', 'required', 'array', 'min:1', 'max:100'],
            'options.choices.*' => ['exclude_unless:type,select,multiselect', 'required', 'string', 'max:150', 'distinct'],
            'options.target' => ['exclude_unless:type,reference', 'required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! RecordReferences::isValidTarget($value)) {
                    $fail('Choisissez le type de fiche visé par le lien.');
                }
            }],
            'options.min' => ['nullable', 'numeric'],
            'options.max' => ['nullable', 'numeric', 'gte:options.min'],
            'options.max_length' => ['nullable', 'integer', 'between:1,255'],
            'options.unit' => ['nullable', 'string', 'max:20'],
            'options.placeholder' => ['nullable', 'string', 'max:120'],
            'options.default' => ['nullable', 'max:255'],
            'options.rows' => ['nullable', 'integer', 'between:2,30'],
            'options.display' => ['nullable', Rule::in(['select', 'radio'])],
            'visibility' => ['nullable', 'array'],
            'visibility.field' => ['nullable', 'string', 'max:60'],
            'visibility.operator' => ['required_with:visibility.field', 'nullable', Rule::in(self::OPERATORS)],
            'visibility.value' => ['nullable'],
            'visibility.value.*' => ['string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.choices.required' => 'Indiquez au moins un choix.',
            'options.choices.*.distinct' => 'Chaque choix doit être unique.',
            'options.target.required' => 'Choisissez le type de fiche visé par le lien.',
            'options.max.gte' => 'Le maximum doit être supérieur ou égal au minimum.',
            'key.prohibited' => 'Le nom technique d\'un champ existant ne peut pas être modifié.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $source = $this->input('visibility.field');

                if ($source === null || $source === '') {
                    return;
                }

                $type = $this->recordType();
                $ownKey = $this->field()?->key ?? $this->input('key');
                $fieldKeys = $type->fields()->pluck('key')->all();
                $builtin = $type->is_system ? array_keys(SystemRecordTypes::conditionSources((string) $type->entity)) : [];

                if ($source === $ownKey || (! in_array($source, $fieldKeys, true) && ! in_array($source, $builtin, true))) {
                    $validator->errors()->add('visibility.field', 'Le champ de la condition est introuvable.');
                }

                $default = $this->input('options.default');
                $choices = (array) $this->input('options.choices', []);

                if ($this->input('type') === 'select' && $default !== null && $default !== '' && ! in_array($default, $choices, true)) {
                    $validator->errors()->add('options.default', 'La valeur par défaut doit faire partie des choix.');
                }
            },
        ];
    }

    /**
     * Données validées, options limitées à celles du type choisi.
     *
     * @return array<string, mixed>
     */
    public function fieldData(): array
    {
        $data = $this->validated();
        $type = RecordFieldType::from($data['type']);
        $options = (array) ($data['options'] ?? []);

        $allowed = match (true) {
            $type->hasChoices() => ['choices', 'default', 'display', 'placeholder'],
            $type === RecordFieldType::Reference => ['target', 'placeholder'],
            $type->isNumeric() => ['min', 'max', 'unit', 'placeholder', 'default'],
            $type === RecordFieldType::Text => ['max_length', 'placeholder', 'default'],
            $type === RecordFieldType::Textarea => ['rows', 'placeholder'],
            $type === RecordFieldType::Boolean, $type === RecordFieldType::Date => ['default'],
            default => ['placeholder'],
        };

        $options = array_filter(
            array_intersect_key($options, array_flip($allowed)),
            fn ($value): bool => $value !== null && $value !== '' && $value !== [],
        );

        if (isset($options['choices'])) {
            $options['choices'] = array_values(array_map(fn ($choice): string => trim((string) $choice), $options['choices']));
        }

        $visibility = $data['visibility'] ?? null;
        $data['visibility'] = is_array($visibility) && ! empty($visibility['field'])
            ? array_filter([
                'field' => (string) $visibility['field'],
                'operator' => (string) ($visibility['operator'] ?? 'equals'),
                'value' => in_array($visibility['operator'] ?? 'equals', ['equals', 'not_equals'], true)
                    ? array_values(array_map('strval', array_filter((array) ($visibility['value'] ?? []), fn ($item): bool => $item !== null && $item !== '')))
                    : null,
            ], fn ($value): bool => $value !== null)
            : null;

        // Unicité et obligation n'ont de sens que pour certaines valeurs.
        $data['is_unique'] = ($data['is_unique'] ?? false) && ! in_array($type, [RecordFieldType::Boolean, RecordFieldType::MultiSelect, RecordFieldType::Textarea, RecordFieldType::RichText], true);
        $data['is_required'] = ($data['is_required'] ?? false) && $type !== RecordFieldType::Boolean;
        $data['in_list'] = ($data['in_list'] ?? false) && $type->canBeListed();
        $data['options'] = $options === [] ? null : $options;

        return $data;
    }
}
