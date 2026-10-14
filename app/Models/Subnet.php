<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sous-réseau / plan d'adressage.
 */
#[Fillable(['site_id', 'vlan_id', 'name', 'cidr', 'gateway', 'dns_servers', 'dhcp_enabled', 'dhcp_range', 'description'])]
class Subnet extends Model
{
    use Auditable, BelongsToClient;

    protected function casts(): array
    {
        return [
            'dhcp_enabled' => 'boolean',
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
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    /**
     * @return HasMany<NetworkInterface, $this>
     */
    public function interfaces(): HasMany
    {
        return $this->hasMany(NetworkInterface::class);
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->cidr.')';
    }

    protected function auditRelations(): array
    {
        return [
            'site_id' => [Site::class, 'name'],
            'vlan_id' => [Vlan::class, 'name'],
        ];
    }

    /**
     * Indique si une adresse IPv4 appartient à ce sous-réseau.
     */
    public function contains(string $ip): bool
    {
        if (! str_contains($this->cidr, '/') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        [$network, $bits] = explode('/', $this->cidr, 2);

        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! ctype_digit($bits) || (int) $bits > 32) {
            return false;
        }

        $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mask) === (ip2long($network) & $mask);
    }
}
