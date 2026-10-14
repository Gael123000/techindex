<?php

namespace App\Services\Vault;

use Illuminate\Http\Request;

/**
 * État de déverrouillage du gestionnaire de mots de passe, conservé dans la
 * session (chiffrée, en base). Il est propre à la session et à l'utilisateur :
 * une autre session, même du même utilisateur, reste verrouillée.
 *
 * Le gestionnaire se reverrouille :
 * - après une période d'inactivité (VAULT_IDLE_TIMEOUT) ;
 * - au bout d'une durée maximale, même en cas d'activité (VAULT_MAX_UNLOCK) ;
 * - à la déconnexion, et à la suppression d'une clé d'accès.
 */
final class VaultLock
{
    private const KEY = 'vault';

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

        $state = $request->session()->get(self::KEY);

        if (! is_array($state) || ($state['user_id'] ?? null) !== $request->user()->getAuthIdentifier()) {
            return 0;
        }

        $now = now()->getTimestamp();
        $idle = self::idleTimeout() - ($now - (int) ($state['activity_at'] ?? 0));
        $absolute = self::maxDuration() - ($now - (int) ($state['unlocked_at'] ?? 0));

        return max(0, min($idle, $absolute));
    }

    public static function unlock(Request $request): void
    {
        $now = now()->getTimestamp();

        $request->session()->put(self::KEY, [
            'user_id' => $request->user()->getAuthIdentifier(),
            'unlocked_at' => $now,
            'activity_at' => $now,
        ]);
    }

    /**
     * Prolonge la période d'inactivité (dans la limite de la durée maximale).
     */
    public static function touch(Request $request): void
    {
        $request->session()->put(self::KEY.'.activity_at', now()->getTimestamp());
    }

    public static function lock(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::KEY);
        }
    }

    public static function idleTimeout(): int
    {
        return max(30, (int) config('techindex.vault.idle_timeout', 300));
    }

    public static function maxDuration(): int
    {
        return max(self::idleTimeout(), (int) config('techindex.vault.max_unlock', 1800));
    }
}
