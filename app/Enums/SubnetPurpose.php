<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Usage d'un sous-réseau dans le plan d'adressage.
 */
enum SubnetPurpose: string
{
    use HasOptions;

    case Lan = 'lan';
    case Wan = 'wan';
    case Dmz = 'dmz';
    case Guest = 'guest';
    case Management = 'management';
    case Voice = 'voice';
    case Vpn = 'vpn';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lan => 'LAN',
            self::Wan => 'WAN (accès Internet)',
            self::Dmz => 'DMZ',
            self::Guest => 'Invités',
            self::Management => 'Administration',
            self::Voice => 'Téléphonie (ToIP)',
            self::Vpn => 'VPN',
            self::Other => 'Autre',
        };
    }
}
