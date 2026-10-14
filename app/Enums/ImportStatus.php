<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportStatus: string
{
    use HasOptions;

    case Uploaded = 'uploaded';
    case Validated = 'validated';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Fichier chargé',
            self::Validated => 'Prêt à importer',
            self::Queued => 'En file d\'attente',
            self::Processing => 'En cours',
            self::Completed => 'Terminé',
            self::Failed => 'Échec',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
