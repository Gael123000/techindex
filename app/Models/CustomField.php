<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * Champ personnalisé typé pour les équipements, commun à tous les types
 * (equipment_type_id nul) ou propre à un type.
 *
 * @property CustomFieldType $type
 * @property list<string>|null $options
 */
#[Fillable(['equipment_type_id', 'key', 'label', 'type', 'options', 'is_required', 'help_text', 'sort_order'])]
class CustomField extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<EquipmentType, $this>
     */
    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class);
    }

    /**
     * Champs applicables à un type d'équipement.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForType(Builder $query, ?int $equipmentTypeId): Builder
    {
        return $query
            ->where(fn (Builder $query) => $query
                ->whereNull('equipment_type_id')
                ->when($equipmentTypeId !== null, fn (Builder $query) => $query->orWhere('equipment_type_id', $equipmentTypeId)))
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    /**
     * Règles de validation de la valeur saisie.
     *
     * @return list<mixed>
     */
    public function valueRules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable'];

        return [...$rules, ...match ($this->type) {
            CustomFieldType::Number => ['numeric', 'between:-999999999999,999999999999'],
            CustomFieldType::Date => ['date'],
            CustomFieldType::Boolean => ['boolean'],
            CustomFieldType::Select => ['string', Rule::in($this->options ?? [])],
            CustomFieldType::Url => ['string', 'max:500', 'url:http,https'],
            CustomFieldType::Text => ['string', 'max:1000'],
        }];
    }
}
