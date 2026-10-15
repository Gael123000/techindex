<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InternetLinkStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Pending = 'pending';
    case Terminated = 'terminated';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'En service',
            self::Pending => 'En cours de mise en service',
            self::Terminated => 'Résilié',
            self::Archived => 'Archivé',
        };
    }
}
