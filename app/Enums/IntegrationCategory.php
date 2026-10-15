<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Familles du catalogue des intégrations.
 */
enum IntegrationCategory: string
{
    use HasOptions;

    case Cloud = 'cloud';
    case Distributor = 'distributor';
    case Security = 'security';
    case Backup = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::Cloud => 'Microsoft et Google',
            self::Distributor => 'Distributeurs et places de marché',
            self::Security => 'Sécurité',
            self::Backup => 'Sauvegarde',
        };
    }
}
