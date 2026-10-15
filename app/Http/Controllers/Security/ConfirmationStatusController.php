<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indique si une confirmation récente de l'identité est encore valable
 * (actions sensibles du compte).
 */
class ConfirmationStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()
            ->json(['confirmed' => RequireRecentPasswordConfirmation::isConfirmed($request)])
            ->header('Cache-Control', 'no-store, private');
    }
}
