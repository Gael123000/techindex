<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CertificateType: string
{
    use HasOptions;

    case Public = 'public';
    case Wildcard = 'wildcard';
    case Internal = 'internal';
    case SelfSigned = 'self_signed';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Autorité publique',
            self::Wildcard => 'Wildcard',
            self::Internal => 'Autorité interne',
            self::SelfSigned => 'Auto-signé',
        };
    }
}
