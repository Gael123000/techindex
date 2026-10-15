<?php

namespace App\Http\Middleware;

use App\Services\Vault\VaultLock;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les mots de passe (gestionnaire, et révélation depuis les fiches) ne sont
 * accessibles qu'une fois déverrouillés par une clé d'accès, pour une durée
 * fixe (VaultLock::DURATION) que l'activité ne prolonge pas. Sinon :
 * - une page affiche l'écran de déverrouillage, à la même adresse ;
 * - un appel JSON reçoit 423, et l'interface propose le déverrouillage ;
 * - une modification est refusée.
 */
class EnsureVaultUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! VaultLock::isUnlocked($request)) {
            VaultLock::lock($request);

            return $this->lockedResponse($request);
        }

        $response = $next($request);

        // Les pages du gestionnaire ne doivent pas rester dans le cache du navigateur.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');

        return $response;
    }

    private function lockedResponse(Request $request): Response
    {
        $message = 'Les mots de passe sont verrouillés.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()
                ->json(['message' => $message.' Déverrouillez-les avec votre clé d\'accès.', 'vault_locked' => true], 423)
                ->header('Cache-Control', 'no-store, private');
        }

        if (! $request->isMethod('GET')) {
            return redirect()->route('vault.index')->with('error', $message.' Déverrouillez-les pour continuer.');
        }

        $user = $request->user();

        return Inertia::render('Vault/Locked', [
            'hasPasskeys' => $user->hasPasskeysEnabled(),
            'lockMinutes' => VaultLock::minutes(),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
