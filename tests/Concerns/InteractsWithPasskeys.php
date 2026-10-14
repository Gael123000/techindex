<?php

namespace Tests\Concerns;

use App\Models\User;
use Tests\Support\FakeAuthenticator;

/**
 * Clés d'accès dans les tests : enregistrement et vérifications réelles,
 * avec un authentificateur logiciel (aucune vérification n'est simulée).
 */
trait InteractsWithPasskeys
{
    protected function configurePasskeys(): void
    {
        config([
            'passkeys.relying_party_id' => 'localhost',
            'passkeys.allowed_origins' => ['http://localhost'],
        ]);
    }

    /**
     * Enregistre une clé d'accès pour l'utilisateur en passant par les routes du profil.
     */
    protected function registerPasskey(User $user, string $name = 'Clé de test'): FakeAuthenticator
    {
        $authenticator = new FakeAuthenticator;

        $options = $this->actingAs($user)->withRecentConfirmation()
            ->getJson(route('profile.passkeys.options'))
            ->assertOk()
            ->json('options');

        $this->postJson(route('profile.passkeys.store'), [
            'name' => $name,
            'credential' => $authenticator->create($options),
        ])->assertOk();

        return $authenticator;
    }

    /**
     * Déverrouille le gestionnaire de mots de passe avec la clé indiquée.
     */
    protected function unlockVault(User $user, FakeAuthenticator $authenticator): void
    {
        $options = $this->actingAs($user)->getJson(route('vault.unlock.options'))->assertOk()->json('options');

        $this->postJson(route('vault.unlock'), ['credential' => $authenticator->get($options)])->assertOk();
    }
}
