<?php

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Fortify (authentification)
|--------------------------------------------------------------------------
|
| Les routes de Fortify ne sont pas chargées automatiquement : elles sont
| déclarées dans routes/auth.php afin d'y ajouter nos propres middlewares
| (compte actif, limitation de débit). Aucune inscription publique.
|
*/

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/dashboard',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => null,
    ],

    /*
    | Clés d'accès (WebAuthn). Les routes du paquet ne sont pas utilisées :
    | TechIndex déclare les siennes (routes/auth.php et routes/web.php) avec
    | ses propres middlewares, messages et entrées d'audit. Une clé d'accès
    | est liée au domaine (relying_party_id) : en changer rend les clés
    | existantes inutilisables.
    */
    'passkeys' => [
        'relying_party_id' => env('PASSKEYS_RP_ID') ?: parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) (env('PASSKEYS_ALLOWED_ORIGINS') ?: env('APP_URL', 'http://localhost')))))),
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', env('APP_KEY')),
        'timeout' => 60000,
    ],

    'views' => true,

    'features' => [
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            'window' => 1,
        ]),
    ],

];
