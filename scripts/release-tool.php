<?php

/*
|--------------------------------------------------------------------------
| TechIndex – outil de publication des versions (sans dépendance)
|--------------------------------------------------------------------------
|
| Utilisé par GitHub Actions (manifeste et signature), par le poste de
| développement (paire de clés) et par scripts/techindex.sh (vérification).
| Signature Ed25519 (libsodium) du manifeste release.json ; le manifeste
| contient l'empreinte SHA-256 de chaque fichier publié.
|
|   php release-tool.php keygen
|   php release-tool.php manifest <dossier> <version> <tag> <commit> <migrations 0|1> <notes.md>
|   php release-tool.php sign <release.json> [clé-publique-attendue]   (clé privée : TECHINDEX_SIGNING_KEY)
|   php release-tool.php verify <release.json> <release.json.sig> <clé-publique>
|   php release-tool.php compare <version-a> <version-b>
|
*/

const MANIFEST_FORMAT = 1;

function fail(string $message): never
{
    fwrite(STDERR, "ERREUR : {$message}\n");
    exit(1);
}

function decodeKey(string $value, int $length, string $label): string
{
    $raw = base64_decode(trim($value), true);

    if ($raw === false || strlen($raw) !== $length) {
        fail("{$label} invalide.");
    }

    return $raw;
}

function validVersion(string $version): bool
{
    return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) === 1;
}

/**
 * @return array<string, mixed>
 */
function verifiedManifest(string $file, string $signatureFile, string $publicKey): array
{
    $content = @file_get_contents($file);
    $signature = base64_decode(trim((string) @file_get_contents($signatureFile)), true);

    if ($content === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        fail('Manifeste ou signature illisible.');
    }

    if (! sodium_crypto_sign_verify_detached($signature, $content, decodeKey($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'Clé publique'))) {
        fail('Signature du manifeste invalide : version refusée.');
    }

    $manifest = json_decode($content, true);

    if (! is_array($manifest) || ($manifest['format'] ?? null) !== MANIFEST_FORMAT || ! validVersion((string) ($manifest['version'] ?? ''))
        || ! is_array($manifest['assets']['plesk'] ?? null) || preg_match('/^[0-9a-f]{64}$/', (string) ($manifest['assets']['plesk']['sha256'] ?? '')) !== 1) {
        fail('Manifeste incomplet ou de format inconnu.');
    }

    return $manifest;
}

$command = $argv[1] ?? '';

switch ($command) {
    case 'keygen':
        $pair = sodium_crypto_sign_keypair();
        echo 'SECRET_KEY=', base64_encode(sodium_crypto_sign_secretkey($pair)), "\n";
        echo 'PUBLIC_KEY=', base64_encode(sodium_crypto_sign_publickey($pair)), "\n";
        break;

    case 'manifest':
        [, , $directory, $version, $tag, $commit, $migrations, $notesFile] = array_pad($argv, 8, '');
        $directory = rtrim($directory, '/');

        if (! validVersion($version) || $tag !== 'v'.$version || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            fail('Version, étiquette ou commit invalide.');
        }

        $asset = static function (string $name) use ($directory): array {
            $path = "{$directory}/{$name}";

            if (! is_file($path)) {
                fail("Fichier absent : {$path}");
            }

            return ['name' => $name, 'sha256' => hash_file('sha256', $path), 'size' => filesize($path)];
        };

        $manifest = [
            'format' => MANIFEST_FORMAT,
            'application' => 'TechIndex',
            'version' => $version,
            'tag' => $tag,
            'commit' => $commit,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'migrations' => $migrations === '1',
            'requirements' => ['php' => '8.4.1', 'mariadb' => '10.11'],
            'notes' => trim((string) @file_get_contents($notesFile)),
            'assets' => [
                'plesk' => $asset("techindex-plesk-{$version}.tar.gz"),
                'script' => $asset('techindex-plesk.sh'),
            ],
        ];

        file_put_contents("{$directory}/release.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        echo "{$directory}/release.json\n";
        break;

    case 'sign':
        $file = $argv[2] ?? '';
        $content = @file_get_contents($file);
        $secret = (string) getenv('TECHINDEX_SIGNING_KEY');

        if ($content === false || $secret === '') {
            fail('Manifeste illisible ou variable TECHINDEX_SIGNING_KEY absente.');
        }

        $secretKey = decodeKey($secret, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'Clé privée TECHINDEX_SIGNING_KEY');
        $expected = $argv[3] ?? '';

        // La clé privée doit correspondre à la clé publique livrée avec le code.
        if ($expected !== '' && ! hash_equals(decodeKey($expected, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'Clé publique'), sodium_crypto_sign_publickey_from_secretkey($secretKey))) {
            fail('La clé privée ne correspond pas à la clé publique de config/release-channel.json.');
        }

        file_put_contents("{$file}.sig", base64_encode(sodium_crypto_sign_detached($content, $secretKey))."\n");
        sodium_memzero($secretKey);
        echo "{$file}.sig\n";
        break;

    case 'verify':
        $manifest = verifiedManifest($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');

        // Sortie « CLÉ=valeur » destinée aux scripts shell.
        foreach ([
            'VERSION' => $manifest['version'],
            'TAG' => $manifest['tag'] ?? '',
            'COMMIT' => $manifest['commit'] ?? '',
            'MIGRATIONS' => ! empty($manifest['migrations']) ? 'oui' : 'non',
            'ARCHIVE' => $manifest['assets']['plesk']['name'],
            'ARCHIVE_SHA256' => $manifest['assets']['plesk']['sha256'],
        ] as $key => $value) {
            if (preg_match('/^[0-9A-Za-z._-]*$/', (string) $value) !== 1) {
                fail("Valeur inattendue dans le manifeste : {$key}");
            }

            echo $key, '=', $value, "\n";
        }
        break;

    case 'compare':
        echo version_compare($argv[2] ?? '', $argv[3] ?? ''), "\n";
        break;

    default:
        fwrite(STDERR, "Usage : php release-tool.php keygen|manifest|sign|verify|compare ...\n");
        exit(2);
}
