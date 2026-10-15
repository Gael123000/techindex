<?php

namespace App\Models;

use App\Enums\EquipmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksAuthors;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Équipement ou élément actif de l'inventaire technique.
 *
 * @property EquipmentStatus $status
 */
#[Table('equipment')]
#[Fillable([
    'site_id', 'equipment_type_id', 'name', 'hostname', 'manufacturer', 'model',
    'serial_number', 'inventory_number', 'location', 'os_name', 'os_version',
    'firmware_version', 'role', 'description', 'status', 'purchase_date',
    'commissioning_date', 'warranty_expires_at', 'end_of_life_at', 'supplier',
    'admin_url', 'notes',
])]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use Auditable, BelongsToClient, HasFactory, HasTags, TracksAuthors;

    protected function casts(): array
    {
        return [
            'status' => EquipmentStatus::class,
            'purchase_date' => 'date:Y-m-d',
            'commissioning_date' => 'date:Y-m-d',
            'warranty_expires_at' => 'date:Y-m-d',
            'end_of_life_at' => 'date:Y-m-d',
        ];
    }

    protected function auditRelations(): array
    {
        return [
            'site_id' => [Site::class, 'name'],
            'equipment_type_id' => [EquipmentType::class, 'name'],
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<EquipmentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    /**
     * @return HasMany<NetworkInterface, $this>
     */
    public function interfaces(): HasMany
    {
        return $this->hasMany(NetworkInterface::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * Relations où cet équipement est la source ("héberge", "est protégé par"...).
     *
     * @return HasMany<EquipmentLink, $this>
     */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(EquipmentLink::class, 'source_equipment_id');
    }

    /**
     * @return HasMany<EquipmentLink, $this>
     */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(EquipmentLink::class, 'target_equipment_id');
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    /**
     * Liens Internet (FAI) terminés ou routés par cet équipement.
     *
     * @return BelongsToMany<InternetLink, $this>
     */
    public function internetLinks(): BelongsToMany
    {
        return $this->belongsToMany(InternetLink::class);
    }

    /**
     * @return BelongsToMany<License, $this>
     */
    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class);
    }

    /**
     * @return BelongsToMany<Certificate, $this>
     */
    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class);
    }

    /**
     * @return BelongsToMany<Document, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class);
    }

    /**
     * @return HasMany<Credential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Résumé des adresses pour l'historique et les exports.
     */
    public function addressSummary(): ?string
    {
        $summary = $this->interfaces()->get()
            ->map(fn (NetworkInterface $interface): string => trim(implode(' ', array_filter([
                $interface->label ? $interface->label.' :' : null,
                $interface->ip_address,
                $interface->mac_address ? '('.$interface->mac_address.')' : null,
            ]))))
            ->filter()
            ->implode(' ; ');

        return $summary === '' ? null : $summary;
    }
}
