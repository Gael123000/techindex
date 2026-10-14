<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EquipmentStatus: string
{
    use HasOptions;

    case InService = 'in_service';
    case Maintenance = 'maintenance';
    case OutOfService = 'out_of_service';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::InService => 'En service',
            self::Maintenance => 'Maintenance',
            self::OutOfService => 'Hors service',
            self::Archived => 'Archivé',
        };
    }
}
