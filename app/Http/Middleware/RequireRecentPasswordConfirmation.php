<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige une confirmation récente du mot de passe pour les opérations
 * sensibles appelées en JSON (révélation d'un secret). Répond 423 afin que
 * l'interface ouvre la fenêtre de confirmation puis relance la demande.
 */
class RequireRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next, string $scope = 'secrets'): Response
    {
        if (! self::isConfirmed($request, $scope)) {
            return response()->json([
                'message' => 'Confirmez votre mot de passe pour continuer.',
                'confirmation_required' => true,
            ], 423)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }

    public static function timeout(string $scope): int
    {
        return $scope === 'secrets'
            ? (int) config('techindex.secrets.reauth_timeout', 600)
            : (int) config('auth.password_timeout', 900);
    }

    public static function isConfirmed(Request $request, string $scope): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > 0 && now()->getTimestamp() - $confirmedAt <= self::timeout($scope);
    }
}
