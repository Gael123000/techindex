<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LicenseStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Cancelled => 'Résiliée',
            self::Archived => 'Archivée',
        };
    }
}
