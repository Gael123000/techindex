<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Interface réseau d'un équipement (une adresse IP et/ou MAC).
 * Les modifications sont journalisées au niveau de l'équipement.
 */
#[Fillable(['label', 'ip_address', 'mac_address', 'subnet_id', 'is_primary', 'notes'])]
class NetworkInterface extends Model
{
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<Subnet, $this>
     */
    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    /**
     * Normalise une adresse MAC au format AA:BB:CC:DD:EE:FF.
     */
    public static function normalizeMac(?string $mac): ?string
    {
        if ($mac === null || trim($mac) === '') {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9a-fA-F]/', '', $mac));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : strtoupper(trim($mac));
    }
}
