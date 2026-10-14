<?php

namespace App\Models;

use App\Enums\ServiceCategory;
use App\Enums\ServiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Service documenté : Internet, VPN, domaine, hébergement, messagerie,
 * application métier, sauvegarde...
 *
 * @property ServiceCategory $category
 * @property ServiceStatus $status
 * @property array<string, mixed>|null $details
 */
#[Fillable([
    'site_id', 'category', 'name', 'provider', 'reference', 'url', 'status',
    'start_date', 'end_date', 'details', 'description', 'notes',
])]
class Service extends Model
{
    use Auditable, BelongsToClient, HasTags, TracksAuthors;

    protected function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'status' => ServiceStatus::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'details' => 'array',
        ];
    }

    protected function auditRelations(): array
    {
        return ['site_id' => [Site::class, 'name']];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsToMany<Equipment, $this>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class);
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
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
