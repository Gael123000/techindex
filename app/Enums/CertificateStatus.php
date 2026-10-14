<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CertificateStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Revoked = 'revoked';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Revoked => 'Révoqué',
            self::Archived => 'Archivé',
        };
    }
}
