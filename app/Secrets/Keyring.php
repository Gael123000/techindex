<?php

namespace App\Secrets;

use App\Secrets\Exceptions\SecretVaultException;

/**
 * Trousseau des clés de chiffrement des secrets.
 *
 * - Clé courante : SECRETS_KEY, ou contenu du fichier SECRETS_KEY_FILE
 *   (recommandé en production avec un secret Docker).
 * - Anciennes clés : SECRETS_PREVIOUS_KEYS, utilisées uniquement pour
 *   déchiffrer pendant une rotation.
 *
 * Chaque clé est identifiée par une empreinte (SHA-256 tronqué) stockée avec
 * chaque secret. L'empreinte ne permet pas de retrouver la clé.
 */
class Keyring
{
    private const KEY_LENGTH = 32;

    /** @var array<string, string>|null */
    private ?array $keys = null;

    private ?string $currentId = null;

    /**
     * @param  list<string>  $previousKeys
     */
    public function __construct(
        private readonly ?string $currentKey,
        private readonly ?string $currentKeyFile,
        private readonly array $previousKeys = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('techindex.secrets.key'),
            config('techindex.secrets.key_file'),
            config('techindex.secrets.previous_keys', []),
        );
    }

    /**
     * @return array{id: string, key: string}
     */
    public function current(): array
    {
        $this->load();

        return ['id' => $this->currentId, 'key' => $this->keys[$this->currentId]];
    }

    public function find(string $keyId): ?string
    {
        $this->load();

        return $this->keys[$keyId] ?? null;
    }

    public function currentId(): string
    {
        return $this->current()['id'];
    }

    /**
     * Empreintes de toutes les clés connues (courante en premier).
     *
     * @return list<string>
     */
    public function fingerprints(): array
    {
        $this->load();

        return array_keys($this->keys);
    }

    public function isConfigured(): bool
    {
        try {
            $this->load();

            return true;
        } catch (SecretVaultException) {
            return false;
        }
    }

    public static function fingerprint(string $rawKey): string
    {
        return substr(hash('sha256', 'techindex-secrets-key|'.$rawKey), 0, 16);
    }

    public static function generate(): string
    {
        return 'base64:'.base64_encode(random_bytes(self::KEY_LENGTH));
    }

    private function load(): void
    {
        if ($this->keys !== null) {
            return;
        }

        $current = $this->currentKey;

        if (($current === null || $current === '') && $this->currentKeyFile !== null && $this->currentKeyFile !== '') {
            if (! is_readable($this->currentKeyFile)) {
                throw SecretVaultException::missingKey();
            }

            $current = trim((string) file_get_contents($this->currentKeyFile));
        }

        if ($current === null || $current === '') {
            throw SecretVaultException::missingKey();
        }

        $raw = $this->decode($current);
        $this->currentId = self::fingerprint($raw);
        $keys = [$this->currentId => $raw];

        foreach ($this->previousKeys as $previous) {
            $rawPrevious = $this->decode($previous);
            $keys[self::fingerprint($rawPrevious)] ??= $rawPrevious;
        }

        $this->keys = $keys;
    }

    private function decode(string $encoded): string
    {
        $encoded = trim($encoded);

        if (! str_starts_with($encoded, 'base64:')) {
            throw SecretVaultException::invalidKey();
        }

        $raw = base64_decode(substr($encoded, 7), true);

        if ($raw === false || strlen($raw) !== self::KEY_LENGTH) {
            throw SecretVaultException::invalidKey();
        }

        return $raw;
    }
}
