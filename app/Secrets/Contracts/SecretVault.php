<?php

namespace App\Secrets\Contracts;

use App\Secrets\Exceptions\SecretVaultException;
use App\Secrets\SealedSecret;

/**
 * Contrat du coffre de secrets.
 *
 * L'implémentation par défaut (LocalSecretVault) chiffre dans l'application.
 * Une intégration future avec un coffre externe (HashiCorp Vault, Azure Key
 * Vault...) consiste à fournir une autre implémentation de ce contrat et à
 * la sélectionner via SECRETS_DRIVER, sans modifier le reste du code.
 */
interface SecretVault
{
    /**
     * Chiffre une valeur en clair. La valeur n'est jamais journalisée.
     */
    public function seal(string $plaintext): SealedSecret;

    /**
     * Déchiffre une valeur scellée.
     *
     * @throws SecretVaultException
     */
    public function unseal(SealedSecret $sealed): string;

    /**
     * Indique si la valeur a été scellée avec une clé autre que la clé courante
     * (utile pour la rotation des clés).
     */
    public function needsResealing(SealedSecret $sealed): bool;
}
