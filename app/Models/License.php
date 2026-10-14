<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Licence, abonnement ou contrat de support.
 *
 * @property LicenseKind $kind
 * @property LicenseStatus $status
 */
#[Fillable([
    'site_id', 'kind', 'name', 'publisher', 'supplier', 'contract_reference',
    'quantity', 'billing_period', 'cost', 'purchase_date', 'starts_at',
    'expires_at', 'auto_renewal', 'renewal_notice_days', 'renewal_notes',
    'status', 'notes',
])]
class License extends Model
{
    use Auditable, BelongsToClient, TracksAuthors;

    protected function casts(): array
    {
        return [
            'kind' => LicenseKind::class,
            'status' => LicenseStatus::class,
            'billing_period' => BillingPeriod::class,
            'quantity' => 'integer',
            'cost' => 'decimal:2',
            'purchase_date' => 'date:Y-m-d',
            'starts_at' => 'date:Y-m-d',
            'expires_at' => 'date:Y-m-d',
            'auto_renewal' => 'boolean',
            'renewal_notice_days' => 'integer',
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
}
