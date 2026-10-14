<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme la session si le compte a été désactivé entre-temps ou si la durée
 * de vie absolue de la session est dépassée (SESSION_ABSOLUTE_LIFETIME),
 * indépendamment de l'activité.
 */
class EnforceSessionPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $authenticatedAt = $request->session()->get('auth.authenticated_at');
        $maxSeconds = (int) config('techindex.security.session_absolute_lifetime') * 60;
        $expired = $maxSeconds > 0 && $authenticatedAt !== null && now()->getTimestamp() - (int) $authenticatedAt > $maxSeconds;

        if (! $user->is_active || $expired) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $expired
                ? 'Votre session a expiré. Veuillez vous reconnecter.'
                : 'Votre compte a été désactivé.';

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->route('login')->with('status', $message);
        }

        if ($authenticatedAt === null) {
            $request->session()->put('auth.authenticated_at', now()->getTimestamp());
        }

        return $next($request);
    }
}
