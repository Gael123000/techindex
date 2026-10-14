<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valeur typée d'un champ personnalisé : une seule des colonnes value_* est
 * renseignée, selon le type du champ.
 */
#[Fillable(['custom_field_id', 'equipment_id', 'value_text', 'value_number', 'value_date', 'value_boolean'])]
class CustomFieldValue extends Model
{
    protected function casts(): array
    {
        return [
            'value_number' => 'float',
            'value_date' => 'date:Y-m-d',
            'value_boolean' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function value(CustomFieldType $type): mixed
    {
        return match ($type) {
            CustomFieldType::Number => $this->value_number,
            CustomFieldType::Date => $this->value_date?->format('Y-m-d'),
            CustomFieldType::Boolean => $this->value_boolean,
            default => $this->value_text,
        };
    }

    public function displayValue(CustomFieldType $type): ?string
    {
        $value = $this->value($type);

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Oui' : 'Non',
            is_float($value) => rtrim(rtrim(number_format($value, 6, ',', ' '), '0'), ','),
            default => (string) $value,
        };
    }
}
