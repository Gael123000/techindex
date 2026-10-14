<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type d'équipement configurable (serveur, switch, onduleur...).
 */
#[Fillable(['name', 'slug', 'icon', 'sort_order'])]
class EquipmentType extends Model
{
    use Auditable;

    public const ICONS = [
        'server', 'server-cog', 'monitor', 'laptop', 'network', 'router', 'shield',
        'wifi', 'hard-drive', 'printer', 'battery-charging', 'phone', 'camera', 'cpu', 'box',
    ];

    /**
     * @return HasMany<Equipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * @return HasMany<CustomField, $this>
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class);
    }
}
