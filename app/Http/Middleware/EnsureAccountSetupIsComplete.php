<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oblige l'utilisateur à changer un mot de passe temporaire et, si son rôle
 * l'exige (TWO_FACTOR_REQUIRED_ROLES), à activer la double authentification
 * avant d'accéder aux données.
 */
class EnsureAccountSetupIsComplete
{
    /**
     * Routes accessibles pendant la configuration du compte.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'profile.*',
        'logout',
        'user-password.update',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'security.*',
        'two-factor.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($user->must_change_password) {
            return $this->redirect($request, 'Définissez un nouveau mot de passe personnel avant de continuer.');
        }

        // Le second facteur peut être un code TOTP ou une clé d'accès.
        if ($user->requiresTwoFactor() && ! $user->hasSecondFactor()) {
            return $this->redirect($request, 'La double authentification est obligatoire pour votre rôle : activez un code TOTP ou ajoutez une clé d\'accès avant de continuer.');
        }

        return $next($request);
    }

    private function redirect(Request $request, string $message): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('profile.show')->with('error', $message);
    }
}
