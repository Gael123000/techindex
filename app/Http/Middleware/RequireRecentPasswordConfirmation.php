<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige une confirmation récente de l'identité (mot de passe ou clé d'accès)
 * pour les opérations sensibles du compte appelées en JSON (ajout d'une clé
 * d'accès, mise à jour). Répond 423 afin que l'interface ouvre la fenêtre de
 * confirmation puis relance la demande.
 *
 * La révélation des secrets n'en dépend pas : elle exige un déverrouillage
 * par clé d'accès (middleware vault.unlocked).
 */
class RequireRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isConfirmed($request)) {
            return response()->json([
                'message' => 'Confirmez votre mot de passe pour continuer.',
                'confirmation_required' => true,
            ], 423)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }

    public static function timeout(): int
    {
        return (int) config('auth.password_timeout', 900);
    }

    public static function isConfirmed(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > 0 && now()->getTimestamp() - $confirmedAt <= self::timeout();
    }
}
