<?php

namespace App\Enums\Concerns;

/**
 * Fournit la liste des valeurs d'une énumération sous forme d'options
 * (valeur + libellé) pour les formulaires et filtres de l'interface.
 *
 * L'énumération doit exposer une méthode label().
 */
trait HasOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
