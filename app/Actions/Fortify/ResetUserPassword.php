<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    public function __construct(
        private readonly UserSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Valide et enregistre le nouveau mot de passe, puis ferme toutes les
     * sessions existantes du compte.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->saveQuietly();

        $this->sessions->revokeAll($user);
        $this->audit->log('auth.password_reset', $user, user: $user);
    }
}
