<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indique si une confirmation récente du mot de passe est valable pour la
 * portée demandée ("default" : profil, "secrets" : révélation des secrets).
 */
class ConfirmationStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $scope = $request->query('scope') === 'secrets' ? 'secrets' : 'default';

        return response()
            ->json(['confirmed' => RequireRecentPasswordConfirmation::isConfirmed($request, $scope)])
            ->header('Cache-Control', 'no-store, private');
    }
}
