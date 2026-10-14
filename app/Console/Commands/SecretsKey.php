<?php

namespace App\Console\Commands;

use App\Secrets\Keyring;
use Illuminate\Console\Command;

/**
 * Génère la clé de chiffrement des secrets clients (SECRETS_KEY).
 *
 * - Sans option : crée la clé uniquement si elle est absente du fichier .env
 *   (jamais d'écrasement, la commande peut être relancée sans risque).
 * - --rotate : place la clé actuelle dans SECRETS_PREVIOUS_KEYS et en génère
 *   une nouvelle ; lancez ensuite techindex:secrets-rotate.
 * - --show : affiche une nouvelle clé sans modifier aucun fichier.
 */
class SecretsKey extends Command
{
    protected $signature = 'techindex:secrets-key
        {--rotate : Remplace la clé courante (l\'ancienne reste utilisable en déchiffrement)}
        {--show : Affiche une clé générée sans modifier le fichier .env}';

    protected $description = 'Génère la clé de chiffrement des secrets si elle est absente';

    public function handle(): int
    {
        if ($this->option('show')) {
            $this->line(Keyring::generate());

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            $this->components->error("Fichier d'environnement introuvable ou non modifiable : {$path}");

            return self::FAILURE;
        }

        $content = (string) file_get_contents($path);
        $current = $this->readValue($content, 'SECRETS_KEY');

        if ($current !== null && $current !== '' && ! $this->option('rotate')) {
            $this->components->info('La clé des secrets existe déjà : aucune modification.');

            return self::SUCCESS;
        }

        if (config('techindex.secrets.key_file') && ($current === null || $current === '')) {
            $this->components->info('La clé des secrets est fournie par SECRETS_KEY_FILE : aucune modification.');

            return self::SUCCESS;
        }

        $new = Keyring::generate();
        $content = $this->writeValue($content, 'SECRETS_KEY', $new);

        if ($this->option('rotate') && $current !== null && $current !== '') {
            $previous = array_filter(array_map('trim', explode(',', (string) $this->readValue($content, 'SECRETS_PREVIOUS_KEYS'))));
            array_unshift($previous, $current);
            $content = $this->writeValue($content, 'SECRETS_PREVIOUS_KEYS', implode(',', array_unique($previous)));
        }

        file_put_contents($path, $content);

        $this->components->info($this->option('rotate')
            ? 'Nouvelle clé générée. Exécutez « php artisan techindex:secrets-rotate » puis sauvegardez la nouvelle clé.'
            : 'Clé des secrets générée dans le fichier .env. Sauvegardez-la immédiatement hors du serveur (voir docs/secrets-et-cles.md).');

        $this->line('  Empreinte de la clé courante : '.Keyring::fingerprint((string) base64_decode(substr($new, 7))));

        return self::SUCCESS;
    }

    private function readValue(string $content, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $content, $matches)) {
            return null;
        }

        return trim($matches[1], " \t\"'\r");
    }

    private function writeValue(string $content, string $key, string $value): string
    {
        $line = $key.'='.$value;

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $content)) {
            return (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $content, 1);
        }

        return rtrim($content, "\r\n").PHP_EOL.$line.PHP_EOL;
    }
}
