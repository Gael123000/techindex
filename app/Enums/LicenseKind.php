<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LicenseKind: string
{
    use HasOptions;

    case License = 'license';
    case Subscription = 'subscription';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::License => 'Licence',
            self::Subscription => 'Abonnement',
            self::Support => 'Contrat de support',
        };
    }
}
