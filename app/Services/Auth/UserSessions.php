<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestion des sessions stockées en base (SESSION_DRIVER=database) :
 * liste, révocation de toutes les sessions d'un compte ou des autres sessions.
 */
class UserSessions
{
    /**
     * Ferme toutes les sessions du compte et invalide le cookie
     * "se souvenir de moi".
     */
    public function revokeAll(User $user): int
    {
        $this->rotateRememberToken($user);

        return $this->query($user)->delete();
    }

    /**
     * Ferme toutes les sessions du compte sauf la session courante.
     */
    public function revokeOthers(User $user, ?string $currentSessionId): int
    {
        $this->rotateRememberToken($user);

        return $this->query($user)
            ->when($currentSessionId !== null, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->delete();
    }

    /**
     * @return Collection<int, array{id: string, ip_address: string|null, user_agent: string, last_active_at: string, is_current: bool}>
     */
    public function list(User $user, ?string $currentSessionId): Collection
    {
        return $this->query($user)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                // L'identifiant de session n'est jamais transmis au navigateur.
                'id' => hash('sha256', $session->id),
                'ip_address' => $session->ip_address,
                'user_agent' => Str::limit((string) $session->user_agent, 160),
                'last_active_at' => now()->setTimestamp((int) $session->last_activity)->toIso8601String(),
                'is_current' => $session->id === $currentSessionId,
            ]);
    }

    private function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    private function query(User $user): Builder
    {
        return DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey());
    }
}
