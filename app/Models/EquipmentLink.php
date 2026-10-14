<?php

namespace App\Models;

use App\Enums\EquipmentLinkType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relation orientée entre deux équipements d'un même client.
 *
 * @property EquipmentLinkType $type
 */
#[Fillable(['source_equipment_id', 'target_equipment_id', 'type', 'notes'])]
class EquipmentLink extends Model
{
    use Auditable, BelongsToClient;

    protected function casts(): array
    {
        return [
            'type' => EquipmentLinkType::class,
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'source_equipment_id');
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'target_equipment_id');
    }

    public function auditLabel(): string
    {
        $this->loadMissing(['source:id,name', 'target:id,name']);

        return sprintf('%s %s %s', $this->source?->name, $this->type->label(), $this->target?->name);
    }
}
