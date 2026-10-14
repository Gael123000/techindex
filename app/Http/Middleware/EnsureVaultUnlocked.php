<?php

namespace App\Http\Middleware;

use App\Services\Vault\VaultLock;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le gestionnaire de mots de passe n'est accessible qu'une fois déverrouillé
 * par une clé d'accès. Sinon :
 * - une page affiche l'écran de déverrouillage, à la même adresse ;
 * - un appel JSON reçoit 423, et l'interface propose le déverrouillage ;
 * - une modification est refusée.
 *
 * Chaque requête autorisée prolonge la période d'inactivité.
 */
class EnsureVaultUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! VaultLock::isUnlocked($request)) {
            VaultLock::lock($request);

            return $this->lockedResponse($request);
        }

        VaultLock::touch($request);

        $response = $next($request);

        // Les pages du gestionnaire ne doivent pas rester dans le cache du navigateur.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');

        return $response;
    }

    private function lockedResponse(Request $request): Response
    {
        $message = 'Le gestionnaire de mots de passe est verrouillé.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()
                ->json(['message' => $message, 'vault_locked' => true], 423)
                ->header('Cache-Control', 'no-store, private');
        }

        if (! $request->isMethod('GET')) {
            return redirect()->route('vault.index')->with('error', $message.' Déverrouillez-le pour continuer.');
        }

        $user = $request->user();

        return Inertia::render('Vault/Locked', [
            'hasPasskeys' => $user->hasPasskeysEnabled(),
            'idleMinutes' => (int) ceil(VaultLock::idleTimeout() / 60),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
