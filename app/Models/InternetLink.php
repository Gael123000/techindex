<?php

namespace App\Models;

use App\Enums\InternetConnectionMode;
use App\Enums\InternetLinkRole;
use App\Enums\InternetLinkStatus;
use App\Enums\InternetTechnology;
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
 * Lien d'accès à Internet d'un client, fourni par un opérateur (FAI).
 *
 * @property InternetLinkRole $role
 * @property InternetLinkStatus $status
 * @property InternetTechnology|null $technology
 * @property InternetConnectionMode|null $connection_mode
 */
#[Fillable([
    'site_id', 'isp_id', 'operator_name', 'name', 'role', 'technology', 'status',
    'download_mbps', 'upload_mbps', 'guaranteed_mbps', 'customer_number',
    'contract_reference', 'line_reference', 'connection_mode', 'connection_username',
    'wan_vlan', 'public_ips', 'wan_gateway', 'dns_servers', 'gtr', 'support_phone',
    'commissioned_at', 'commitment_ends_at', 'monthly_cost', 'description', 'notes',
])]
class InternetLink extends Model
{
    use Auditable, BelongsToClient, HasTags, TracksAuthors;

    protected function casts(): array
    {
        return [
            'role' => InternetLinkRole::class,
            'status' => InternetLinkStatus::class,
            'technology' => InternetTechnology::class,
            'connection_mode' => InternetConnectionMode::class,
            'download_mbps' => 'integer',
            'upload_mbps' => 'integer',
            'guaranteed_mbps' => 'integer',
            'wan_vlan' => 'integer',
            'commissioned_at' => 'date:Y-m-d',
            'commitment_ends_at' => 'date:Y-m-d',
            'monthly_cost' => 'decimal:2',
        ];
    }

    protected function auditRelations(): array
    {
        return [
            'site_id' => [Site::class, 'name'],
            'isp_id' => [Isp::class, 'name'],
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
     * @return BelongsTo<Isp, $this>
     */
    public function isp(): BelongsTo
    {
        return $this->belongsTo(Isp::class);
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
     * Sous-réseaux desservis (WAN, bloc d'adresses publiques...).
     *
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Nom de l'opérateur : catalogue, sinon saisie libre.
     * La relation isp doit être chargée.
     */
    public function operatorLabel(): ?string
    {
        return $this->isp?->name ?? $this->operator_name;
    }

    /**
     * Débits lisibles : « 1 Gbit/s ↓ / 500 Mbit/s ↑ ».
     */
    public function bandwidthLabel(): ?string
    {
        $parts = array_filter([
            $this->download_mbps !== null ? self::formatMbps($this->download_mbps).' ↓' : null,
            $this->upload_mbps !== null ? self::formatMbps($this->upload_mbps).' ↑' : null,
        ]);

        return $parts === [] ? null : implode(' / ', $parts);
    }

    public static function formatMbps(int $mbps): string
    {
        if ($mbps >= 1000 && $mbps % 100 === 0) {
            return str_replace('.', ',', rtrim(rtrim(number_format($mbps / 1000, 1, '.', ''), '0'), '.')).' Gbit/s';
        }

        return $mbps.' Mbit/s';
    }

    /**
     * Convertit un débit saisi librement (« 1 Gbit/s », « 500 Mb/s », « 20M »)
     * en Mbit/s ; nul si le texte n'est pas reconnu.
     */
    public static function parseMbps(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = str_replace(',', '.', mb_strtolower(trim($value)));

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(g|m|k)?\s*(?:bit|b|bps|bits)?(?:\/s|ps)?$/u', $normalized, $matches) !== 1) {
            return null;
        }

        $number = (float) $matches[1];
        $mbps = match ($matches[2] ?? 'm') {
            'g' => $number * 1000,
            'k' => $number / 1000,
            default => $number,
        };

        return $mbps >= 1 && $mbps <= 4_000_000 ? (int) round($mbps) : null;
    }
}
