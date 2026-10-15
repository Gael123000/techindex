<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Rôle d'un lien Internet dans l'architecture du client.
 */
enum InternetLinkRole: string
{
    use HasOptions;

    case Primary = 'primary';
    case Backup = 'backup';
    case Aggregated = 'aggregated';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Lien principal',
            self::Backup => 'Lien de secours',
            self::Aggregated => 'Agrégation / répartition de charge',
            self::Other => 'Autre usage',
        };
    }
}
