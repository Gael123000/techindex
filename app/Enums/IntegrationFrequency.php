<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Carbon\CarbonInterface;

/**
 * Fréquence de synchronisation automatique d'une intégration.
 */
enum IntegrationFrequency: string
{
    use HasOptions;

    case Manual = 'manual';
    case Every6Hours = 'every_6_hours';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuelle uniquement',
            self::Every6Hours => 'Toutes les 6 heures',
            self::Daily => 'Une fois par jour',
        };
    }

    /**
     * Synchronisation automatique due, d'après la dernière exécution.
     */
    public function isDue(?CarbonInterface $lastSyncedAt, CarbonInterface $now): bool
    {
        return match ($this) {
            self::Manual => false,
            self::Every6Hours => $lastSyncedAt === null || $lastSyncedAt->lte($now->copy()->subHours(6)->addMinutes(5)),
            self::Daily => $lastSyncedAt === null || $lastSyncedAt->lte($now->copy()->subDay()->addMinutes(5)),
        };
    }
}
