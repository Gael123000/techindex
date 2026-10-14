<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    public function __construct(
        private readonly UserSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Change le mot de passe de l'utilisateur connecté. Les autres sessions
     * du compte sont fermées ; la session courante est conservée.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
        ], [
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->saveQuietly();

        $this->sessions->revokeOthers($user, request()->session()->getId());
        $this->audit->log('auth.password_changed', $user, user: $user);
    }
}
