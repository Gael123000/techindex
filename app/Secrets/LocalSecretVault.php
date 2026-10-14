<?php

namespace App\Secrets;

use App\Secrets\Contracts\SecretVault;
use App\Secrets\Exceptions\SecretVaultException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Coffre local : chiffrement authentifié AES-256-GCM via l'Encrypter de
 * Laravel (OpenSSL), avec une clé dédiée distincte d'APP_KEY.
 *
 * Aucune primitive cryptographique n'est implémentée ici : la génération du
 * vecteur d'initialisation, le chiffrement et la vérification du tag
 * d'authentification sont délégués à l'Encrypter.
 */
class LocalSecretVault implements SecretVault
{
    /** @var array<string, Encrypter> */
    private array $encrypters = [];

    public function __construct(
        private readonly Keyring $keyring,
        private readonly string $cipher = 'aes-256-gcm',
    ) {}

    public function seal(string $plaintext): SealedSecret
    {
        $current = $this->keyring->current();

        return new SealedSecret(
            $this->encrypter($current['id'], $current['key'])->encryptString($plaintext),
            $current['id'],
        );
    }

    public function unseal(SealedSecret $sealed): string
    {
        $key = $this->keyring->find($sealed->keyId);

        if ($key === null) {
            throw SecretVaultException::unknownKey($sealed->keyId);
        }

        try {
            return $this->encrypter($sealed->keyId, $key)->decryptString($sealed->ciphertext);
        } catch (DecryptException) {
            throw SecretVaultException::decryptionFailed();
        }
    }

    public function needsResealing(SealedSecret $sealed): bool
    {
        return $sealed->keyId !== $this->keyring->currentId();
    }

    private function encrypter(string $keyId, string $key): Encrypter
    {
        return $this->encrypters[$keyId] ??= new Encrypter($key, $this->cipher);
    }
}
