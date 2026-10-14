<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportType: string
{
    use HasOptions;

    case Clients = 'clients';
    case Equipment = 'equipment';

    public function label(): string
    {
        return match ($this) {
            self::Clients => 'Clients',
            self::Equipment => 'Équipements',
        };
    }
}
