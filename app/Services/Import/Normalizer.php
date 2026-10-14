<?php

namespace App\Services\Import;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Conversion des valeurs brutes d'un tableur.
 */
final class Normalizer
{
    public static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Accepte AAAA-MM-JJ, JJ/MM/AAAA, JJ-MM-AAAA et JJ.MM.AAAA. Une valeur
     * non reconnue est conservée telle quelle pour produire une erreur de validation.
     */
    public static function date(?string $value): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date !== null && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $value;
    }

    /**
     * Liste de valeurs séparées par virgule, point-virgule, barre verticale ou espace.
     *
     * @return list<string>
     */
    public static function list(?string $value, bool $splitOnSpaces = false): array
    {
        $pattern = $splitOnSpaces ? '/[,;|\s]+/u' : '/[,;|]+/u';

        return array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split($pattern, (string) $value) ?: [],
        ), fn (string $item): bool => $item !== '')));
    }

    /**
     * Clé de comparaison : minuscules, sans accents ni ponctuation.
     */
    public static function key(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $value)));
    }
}
