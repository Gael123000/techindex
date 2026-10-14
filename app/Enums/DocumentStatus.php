<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DocumentStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'Publié',
            self::Archived => 'Archivé',
        };
    }
}
