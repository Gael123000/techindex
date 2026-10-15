<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client ou locataire connu d'un service externe (client Pax8, locataire
 * Microsoft 365...), rattaché à un client TechIndex pour y ranger ses licences.
 *
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['external_id', 'name', 'domain', 'metadata', 'client_id', 'is_ignored', 'is_manual'])]
class IntegrationAccount extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_ignored' => 'boolean',
            'is_manual' => 'boolean',
            'client_id' => 'integer',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'licenses_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<License, $this>
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }
}
