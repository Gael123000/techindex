<?php

namespace App\Secrets\Exceptions;

use RuntimeException;

/**
 * Erreur du coffre. Le message ne contient jamais de valeur secrète ni de clé.
 */
class SecretVaultException extends RuntimeException
{
    public static function missingKey(): self
    {
        return new self('Aucune clé de chiffrement des secrets n\'est configurée (SECRETS_KEY ou SECRETS_KEY_FILE).');
    }

    public static function invalidKey(): self
    {
        return new self('La clé de chiffrement des secrets est invalide : 32 octets attendus au format "base64:...".');
    }

    public static function unknownKey(string $keyId): self
    {
        return new self("La clé de chiffrement \"{$keyId}\" n'est pas disponible. Ajoutez-la à SECRETS_PREVIOUS_KEYS pour déchiffrer ce secret.");
    }

    public static function decryptionFailed(): self
    {
        return new self('Le secret n\'a pas pu être déchiffré (clé incorrecte ou données altérées).');
    }
}
