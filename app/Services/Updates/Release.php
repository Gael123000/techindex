<?php

namespace App\Services\Updates;

use Illuminate\Support\Str;

/**
 * Version publiée, décrite par un manifeste dont la signature a été vérifiée.
 */
final readonly class Release
{
    public const VERSION_PATTERN = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/';

    public function __construct(
        public string $version,
        public string $tag,
        public string $commit,
        public ?string $publishedAt,
        public bool $migrations,
        public string $notes,
        public string $archive,
        public string $sha256,
        public int $size,
    ) {}

    /**
     * Vérifie la signature Ed25519 du manifeste avec la clé publique livrée
     * avec le code, puis le lit. Toute anomalie refuse la version.
     */
    public static function fromSignedManifest(string $content, string $signature, string $publicKey): self
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new UpdateException('L\'extension PHP sodium est nécessaire pour vérifier les mises à jour.');
        }

        $rawSignature = base64_decode(trim($signature), true);
        $rawKey = base64_decode(trim($publicKey), true);

        if ($rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || $rawKey === false || strlen($rawKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($rawSignature, $content, $rawKey)) {
            throw new UpdateException('Signature invalide : cette version ne provient pas du canal officiel et a été ignorée.');
        }

        $data = json_decode($content, true);
        $archive = is_array($data) ? ($data['assets']['plesk'] ?? null) : null;

        if (! is_array($data) || ($data['format'] ?? null) !== 1 || ! is_array($archive)
            || preg_match(self::VERSION_PATTERN, (string) ($data['version'] ?? '')) !== 1
            || preg_match('/^techindex-plesk-[0-9A-Za-z.-]+\.tar\.gz$/', (string) ($archive['name'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/', (string) ($archive['sha256'] ?? '')) !== 1) {
            throw new UpdateException('Manifeste de version incomplet ou de format inconnu.');
        }

        return new self(
            version: (string) $data['version'],
            tag: (string) ($data['tag'] ?? 'v'.$data['version']),
            commit: (string) ($data['commit'] ?? ''),
            publishedAt: isset($data['published_at']) ? (string) $data['published_at'] : null,
            migrations: (bool) ($data['migrations'] ?? true),
            notes: Str::limit((string) ($data['notes'] ?? ''), 20000),
            archive: (string) $archive['name'],
            sha256: (string) $archive['sha256'],
            size: (int) ($archive['size'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'tag' => $this->tag,
            'commit' => $this->commit,
            'published_at' => $this->publishedAt,
            'migrations' => $this->migrations,
            'notes' => $this->notes,
            'archive' => $this->archive,
            'size' => $this->size,
        ];
    }
}
