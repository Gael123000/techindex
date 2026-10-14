<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\TracksAuthors;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Site ou établissement d'un client.
 *
 * @property RecordStatus $status
 */
#[Fillable([
    'name', 'code', 'address_line1', 'address_line2', 'postal_code', 'city',
    'country', 'phone', 'access_instructions', 'notes',
])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use Auditable, BelongsToClient, HasFactory, TracksAuthors;

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<Equipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class);
    }

    /**
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function fullAddress(): string
    {
        return collect([
            $this->address_line1,
            $this->address_line2,
            trim(($this->postal_code ?? '').' '.($this->city ?? '')),
            $this->country,
        ])->filter()->implode(', ');
    }
}
