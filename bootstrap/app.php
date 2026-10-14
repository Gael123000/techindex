<?php

use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureAccountSetupIsComplete;
use App\Http\Middleware\EnsureVaultUnlocked;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventCaching;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Http\Middleware\SecurityHeaders;
use App\Secrets\Exceptions\SecretVaultException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/auth.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hors environnement local : seules les requêtes adressées au domaine
        // d'APP_URL sont acceptées (protection contre l'empoisonnement de l'en-tête Host).
        $middleware->trustHosts();

        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'session.policy' => EnforceSessionPolicy::class,
            'account.setup' => EnsureAccountSetupIsComplete::class,
            'recently-confirmed' => RequireRecentPasswordConfirmation::class,
            'no-store' => PreventCaching::class,
            'vault.unlocked' => EnsureVaultUnlocked::class,
        ]);

        // Proxys de confiance : voir TRUSTED_PROXIES (appliqué dans AppServiceProvider,
        // une fois la configuration chargée).

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Jamais de mots de passe ni de secrets dans la session flash
        // (ancienne saisie réaffichée après une erreur de validation).
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'secret',
            'code',
            'recovery_code',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );

        // Une erreur de déchiffrement est signalée sans détail technique.
        $exceptions->render(function (SecretVaultException $exception, Request $request) {
            return response()->json(['message' => $exception->getMessage()], 409)
                ->header('Cache-Control', 'no-store, private');
        });

        // Pages d'erreur Inertia sans fuite d'information en production.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            if (! app()->hasDebugModeEnabled() && in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                if ($status === 419) {
                    return back()->with('error', 'La page a expiré, veuillez réessayer.');
                }

                return Inertia::render('Errors/Error', [
                    'status' => $status,
                    'message' => $status === 403 && $exception->getMessage() !== '' && $exception->getMessage() !== 'This action is unauthorized.'
                        ? $exception->getMessage()
                        : null,
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
