<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Statut des clients et des sites.
 */
enum RecordStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Archived => 'Archivé',
        };
    }
}
