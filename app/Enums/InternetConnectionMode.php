<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Mode de connexion au réseau de l'opérateur (côté WAN).
 */
enum InternetConnectionMode: string
{
    use HasOptions;

    case Dhcp = 'dhcp';
    case Pppoe = 'pppoe';
    case Static = 'static';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Dhcp => 'DHCP / IPoE',
            self::Pppoe => 'PPPoE',
            self::Static => 'Adressage statique',
            self::Other => 'Autre',
        };
    }
}
