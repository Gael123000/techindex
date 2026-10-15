<?php

namespace App\Services\Vault;

use Illuminate\Http\Request;

/**
 * État de déverrouillage des mots de passe, conservé dans la session
 * (chiffrée, en base). Il est propre à la session et à l'utilisateur : une
 * autre session, même du même utilisateur, reste verrouillée.
 *
 * Le même verrou protège le gestionnaire de mots de passe et l'affichage
 * des secrets sur les fiches (équipements, services, accès) : un
 * déverrouillage depuis l'un vaut pour l'autre.
 *
 * Il se reverrouille :
 * - 5 minutes après son ouverture, même en cas d'activité (durée fixe) ;
 * - à la déconnexion, à la suppression d'une clé d'accès, ou manuellement.
 */
final class VaultLock
{
    /**
     * Durée d'ouverture, en secondes. Volontairement non configurable :
     * l'activité ne la prolonge pas, seule une nouvelle vérification par
     * clé d'accès rouvre les mots de passe.
     */
    public const DURATION = 300;

    public const SESSION_KEY = 'vault';

    public static function isUnlocked(Request $request): bool
    {
        return self::expiresIn($request) > 0;
    }

    /**
     * Secondes avant le reverrouillage automatique (0 si verrouillé).
     */
    public static function expiresIn(Request $request): int
    {
        if (! $request->hasSession() || $request->user() === null) {
            return 0;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ($state['user_id'] ?? null) !== $request->user()->getAuthIdentifier()) {
            return 0;
        }

        $elapsed = now()->getTimestamp() - (int) ($state['unlocked_at'] ?? 0);

        return max(0, min(self::DURATION, self::DURATION - $elapsed));
    }

    public static function unlock(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $request->user()->getAuthIdentifier(),
            'unlocked_at' => now()->getTimestamp(),
        ]);
    }

    public static function lock(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    public static function minutes(): int
    {
        return intdiv(self::DURATION, 60);
    }
}
