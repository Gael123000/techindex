<?php

namespace App\Services\Audit;

/**
 * Filet de sécurité : retire des données journalisées toute valeur dont la
 * clé évoque un secret, même si l'appelant l'a transmise par erreur.
 */
final class Redactor
{
    private const SENSITIVE_PATTERNS = [
        'password',
        'passwd',
        'secret',
        'token',
        'ciphertext',
        'recovery_code',
        'api_key',
        'private_key',
        'two_factor',
    ];

    public const REDACTED = '[masqué]';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                // On conserve la trace d'une modification sans sa valeur.
                $clean[$key] = is_array($value) && array_key_exists('old', $value)
                    ? ['old' => null, 'new' => self::REDACTED]
                    : self::REDACTED;

                continue;
            }

            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $clean;
    }

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
