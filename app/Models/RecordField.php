<?php

namespace App\Models;

use App\Enums\RecordFieldType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Champ d'une fiche, stocké dans la colonne column_name de la table de la
 * fiche. L'identifiant (key) et la colonne sont fixés à la création.
 *
 * @property RecordFieldType $type
 * @property array<string, mixed>|null $options
 * @property array{field: string, operator: string, value?: mixed}|null $visibility
 */
#[Fillable([
    'label', 'type', 'options', 'help_text', 'is_required', 'is_unique', 'in_list', 'is_searchable',
    'section', 'width', 'sort_order', 'visibility',
])]
class RecordField extends Model
{
    use Auditable;

    /** Largeurs possibles dans une section : une colonne, deux colonnes, toute la largeur. */
    public const WIDTHS = ['1', '2', 'full'];

    protected function casts(): array
    {
        return [
            'type' => RecordFieldType::class,
            'options' => 'array',
            'visibility' => 'array',
            'is_required' => 'boolean',
            'is_unique' => 'boolean',
            'is_title' => 'boolean',
            'in_list' => 'boolean',
            'is_searchable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RecordType, $this>
     */
    public function recordType(): BelongsTo
    {
        return $this->belongsTo(RecordType::class);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function choices(): array
    {
        return array_values(array_map('strval', (array) $this->option('choices', [])));
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    protected function auditExcluded(): array
    {
        return ['sort_order', 'section', 'width'];
    }
}
