<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\User;
use App\Services\Vault\VaultLock;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Outils communs aux tests : comptes, affectations, confirmation récente,
 * mots de passe déverrouillés.
 */
trait InteractsWithTechIndex
{
    /**
     * Garde-fou : les tests vident la base. Ils refusent de s'exécuter sur une
     * base dont le nom ne se termine pas par "_test" (par exemple si une
     * variable d'environnement DB_DATABASE réelle prend le pas sur phpunit.xml).
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests interrompus : la base « {$database} » n'est pas une base de test (nom attendu se terminant par _test). Vérifiez DB_DATABASE.");
        }

        return parent::setUpTraits();
    }

    protected function setUpTechIndex(): void
    {
        Storage::fake('local');
        $this->seed(ReferenceDataSeeder::class);
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Crée un utilisateur affecté au client avec les droits par défaut de son
     * rôle, éventuellement ajustés.
     *
     * @param  array<string, bool>  $overrides
     */
    protected function userFor(Client $client, UserRole $role = UserRole::Technician, array $overrides = []): User
    {
        $user = User::factory()->role($role)->create();
        $this->assign($user, $client, $overrides);

        return $user;
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    protected function assign(User $user, Client $client, array $overrides = []): ClientAssignment
    {
        $assignment = ClientAssignment::query()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            ...$user->role->defaultClientPermissions(),
            ...$overrides,
        ]);

        $user->flushAccessCache();

        return $assignment;
    }

    /**
     * Simule une confirmation récente du mot de passe.
     */
    protected function withRecentConfirmation(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp(), 'auth.authenticated_at' => now()->getTimestamp()]);
    }

    /**
     * Simule des mots de passe déverrouillés par clé d'accès à l'instant
     * (la cérémonie elle-même est testée dans VaultTest).
     */
    protected function withUnlockedVault(User $user): static
    {
        return $this->withSession([VaultLock::SESSION_KEY => ['user_id' => $user->getKey(), 'unlocked_at' => now()->getTimestamp()]]);
    }
}
