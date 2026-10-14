<?php

namespace App\Providers;

use App\Actions\Fortify\RedirectIfSecondFactorRequired;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Les routes sont déclarées dans routes/auth.php.
        Fortify::ignoreRoutes();

        // Réponse identique que l'adresse existe ou non : pas d'énumération des comptes.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);

        // Second facteur exigé dès qu'un code TOTP OU une clé d'accès est configuré.
        $this->app->bind(RedirectsIfTwoFactorAuthenticatable::class, RedirectIfSecondFactorRequired::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::loginView(fn (Request $request) => Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
        ]));

        // Méthodes proposées selon ce que l'utilisateur a configuré (rien
        // d'autre n'est révélé sur le compte à ce stade).
        Fortify::twoFactorChallengeView(function (Request $request) {
            $user = User::query()->find($request->session()->get('login.id'));

            return Inertia::render('Auth/TwoFactorChallenge', [
                'methods' => [
                    'totp' => $user?->hasEnabledTwoFactorAuthentication() ?? false,
                    'passkey' => $user?->hasPasskeysEnabled() ?? false,
                ],
            ]);
        });

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => (string) $request->query('email', ''),
            'token' => (string) $request->route('token'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));
    }
}
