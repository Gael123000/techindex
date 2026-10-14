<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Certificat (TLS, VPN...). La clé privée n'est jamais stockée ici : si elle
 * doit être conservée, elle l'est dans un accès sécurisé (module secrets).
 *
 * @property CertificateType $type
 * @property CertificateStatus $status
 */
#[Fillable([
    'site_id', 'service_id', 'name', 'common_name', 'subject_alt_names', 'issuer',
    'type', 'serial_number', 'fingerprint_sha256', 'issued_at', 'expires_at',
    'auto_renewal', 'renewal_method', 'status', 'notes',
])]
class Certificate extends Model
{
    use Auditable, BelongsToClient, TracksAuthors;

    protected function casts(): array
    {
        return [
            'type' => CertificateType::class,
            'status' => CertificateStatus::class,
            'issued_at' => 'date:Y-m-d',
            'expires_at' => 'date:Y-m-d',
            'auto_renewal' => 'boolean',
        ];
    }

    protected function auditRelations(): array
    {
        return [
            'site_id' => [Site::class, 'name'],
            'service_id' => [Service::class, 'name'],
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
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsToMany<Equipment, $this>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class);
    }
}
