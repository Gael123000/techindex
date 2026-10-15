<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Refuse un champ de fiche destiné à un secret (mot de passe, clé, jeton...).
 *
 * Les champs des fiches sont stockés en clair, affichés, recherchés et
 * exportés : un secret se conserve dans un accès chiffré (rubrique Accès),
 * qu'un champ « lien vers une fiche » peut désigner.
 */
class NotSecretField implements ValidationRule
{
    private const PATTERNS = [
        '/\bmots? de passe\b/', '/\bpass ?words?\b/', '/\bpasswd\b/', '/\bmdp\b/', '/\bsecrets?\b/', '/\btokens?\b/',
        '/\bjetons?\b/', '/\bpassphrase\b/', '/\bcles? (d )?api\b/', '/\bapi ?keys?\b/', '/\bcles? privees?\b/',
        '/\bprivate ?keys?\b/', '/\bcodes? pin\b/', '/\bpin\b/', '/\bpuk\b/', '/\bcles? wi ?fi\b/', '/\bwpa\d?\b/', '/\bpsk\b/',
        '/\bcles? partagees?\b/', '/\bshared secret\b/', '/\bcles? de licence\b/', '/\blicense ?keys?\b/',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $value)))).' ';

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $fail('Un secret (mot de passe, clé, jeton...) ne se conserve pas dans un champ de fiche : utilisez un accès chiffré (rubrique « Accès »), et un champ « Lien vers une fiche » vers cet accès si besoin.');

                return;
            }
        }
    }
}
