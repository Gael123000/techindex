<?php

use App\Http\Controllers\Auth\PasskeyChallengeController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Security\PasskeyConfirmationController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

/*
|--------------------------------------------------------------------------
| Authentification (contrôleurs Fortify)
|--------------------------------------------------------------------------
|
| Routes déclarées explicitement afin de maîtriser les middlewares :
| limitation de débit sur chaque point d'entrée sensible, aucune route
| d'inscription publique.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.update');

    // Second facteur, après un mot de passe correct : code TOTP, code de
    // récupération ou clé d'accès.
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.login.store');
    Route::get('/two-factor-challenge/passkey/options', [PasskeyChallengeController::class, 'options'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.passkey.options');
    Route::post('/two-factor-challenge/passkey', [PasskeyChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.passkey.store');
});

Route::middleware(['auth', 'session.policy'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/user/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/user/confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:password-confirmation')
        ->name('password.confirm.store');
    Route::get('/user/confirmed-password-status', [ConfirmedPasswordStatusController::class, 'show'])->name('password.confirmation');

    // Confirmation de l'identité par clé d'accès, à la place du mot de passe.
    Route::get('/user/confirm-passkey/options', [PasskeyConfirmationController::class, 'options'])
        ->middleware('throttle:passkeys')
        ->name('security.passkey-confirmation.options');
    Route::post('/user/confirm-passkey', [PasskeyConfirmationController::class, 'store'])
        ->middleware('throttle:passkeys')
        ->name('security.passkey-confirmation.store');

    Route::put('/user/password', [PasswordController::class, 'update'])->name('user-password.update');

    // Gestion de la double authentification : confirmation du mot de passe requise.
    Route::middleware(['password.confirm', 'no-store'])->group(function (): void {
        Route::post('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
        Route::post('/user/confirmed-two-factor-authentication', [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->middleware('throttle:two-factor-setup')
            ->name('two-factor.confirm');
        Route::delete('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
        Route::get('/user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])->name('two-factor.qr-code');
        Route::get('/user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])->name('two-factor.secret-key');
        Route::get('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])->name('two-factor.recovery-codes');
        Route::post('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])->name('two-factor.regenerate-recovery-codes');
    });
});
