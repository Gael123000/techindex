<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Choix explicite du comportement face aux enregistrements existants.
 */
enum ImportMode: string
{
    use HasOptions;

    case Create = 'create';
    case Update = 'update';
    case Upsert = 'upsert';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Créer uniquement (les doublons sont ignorés)',
            self::Update => 'Mettre à jour uniquement (les lignes inconnues sont ignorées)',
            self::Upsert => 'Créer et mettre à jour',
        };
    }

    public function allowsCreate(): bool
    {
        return $this !== self::Update;
    }

    public function allowsUpdate(): bool
    {
        return $this !== self::Create;
    }
}
