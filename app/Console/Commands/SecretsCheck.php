<?php

namespace App\Console\Commands;

use App\Models\Credential;
use App\Secrets\Contracts\SecretVault;
use App\Secrets\Exceptions\SecretVaultException;
use App\Secrets\Keyring;
use App\Secrets\SealedSecret;
use Illuminate\Console\Command;

/**
 * Vérifie que tous les secrets stockés sont déchiffrables avec les clés
 * configurées (à lancer après une restauration ou une rotation). Aucune
 * valeur n'est affichée.
 */
class SecretsCheck extends Command
{
    protected $signature = 'techindex:secrets-check {--json : Sortie JSON (scripts de sauvegarde)}';

    protected $description = 'Vérifie que les secrets stockés sont déchiffrables';

    public function handle(SecretVault $vault, Keyring $keyring): int
    {
        if (! $keyring->isConfigured()) {
            $this->components->error('Aucune clé de chiffrement des secrets valide n\'est configurée.');

            return self::FAILURE;
        }

        $total = 0;
        $failed = 0;
        $byKey = [];

        Credential::query()->whereNotNull('secret_ciphertext')->orderBy('id')->chunkById(200, function ($credentials) use ($vault, &$total, &$failed, &$byKey): void {
            foreach ($credentials as $credential) {
                $total++;
                $byKey[$credential->secret_key_id] = ($byKey[$credential->secret_key_id] ?? 0) + 1;

                try {
                    $vault->unseal(new SealedSecret($credential->secret_ciphertext, (string) $credential->secret_key_id));
                } catch (SecretVaultException) {
                    $failed++;
                }
            }
        });

        $result = [
            'current_key' => $keyring->currentId(),
            'known_keys' => $keyring->fingerprints(),
            'secrets' => $total,
            'failed' => $failed,
            'by_key' => $byKey,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->components->twoColumnDetail('Clé courante', $result['current_key']);
            $this->components->twoColumnDetail('Secrets stockés', (string) $total);
            $this->components->twoColumnDetail('Échecs de déchiffrement', (string) $failed);

            foreach ($byKey as $key => $count) {
                $this->components->twoColumnDetail("  scellés avec {$key}", (string) $count);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
