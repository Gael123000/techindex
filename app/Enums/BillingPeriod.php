<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum BillingPeriod: string
{
    use HasOptions;

    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case MultiYear = 'multi_year';
    case Perpetual = 'perpetual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensuelle',
            self::Yearly => 'Annuelle',
            self::MultiYear => 'Pluriannuelle',
            self::Perpetual => 'Perpétuelle',
            self::Other => 'Autre',
        };
    }
}
