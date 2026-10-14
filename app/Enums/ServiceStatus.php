<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ServiceStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Inactive => 'Inactif',
            self::Archived => 'Archivé',
        };
    }
}
