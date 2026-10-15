<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum IntegrationRunStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En attente',
            self::Running => 'En cours',
            self::Success => 'Réussie',
            self::Partial => 'Réussie avec des erreurs',
            self::Failed => 'Échec',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed], true);
    }
}
