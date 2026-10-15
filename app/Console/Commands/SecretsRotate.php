<?php

namespace App\Console\Commands;

use App\Secrets\CredentialSecrets;
use App\Secrets\IntegrationSecrets;
use Illuminate\Console\Command;

/**
 * Rechiffre tous les secrets avec la clé courante (après techindex:secrets-key --rotate) :
 * accès des clients et identifiants d'API des intégrations.
 */
class SecretsRotate extends Command
{
    protected $signature = 'techindex:secrets-rotate';

    protected $description = 'Rechiffre les secrets avec la clé courante';

    public function handle(CredentialSecrets $secrets, IntegrationSecrets $integrations): int
    {
        $result = $secrets->rotate();
        $integrationResult = $integrations->rotate();

        $this->components->info("{$result['rotated']} secret(s) rechiffré(s), {$integrationResult['rotated']} intégration(s).");

        if ($result['failed'] > 0 || $integrationResult['failed'] > 0) {
            $failed = $result['failed'] + $integrationResult['failed'];
            $this->components->error("{$failed} secret(s) n'ont pas pu être déchiffrés : vérifiez SECRETS_PREVIOUS_KEYS avant de retirer une ancienne clé.");

            return self::FAILURE;
        }

        $this->line('  Gardez les anciennes clés dans SECRETS_PREVIOUS_KEYS tant que des sauvegardes antérieures à la rotation sont conservées :');
        $this->line('  elles sont nécessaires pour les restaurer (voir docs/secrets-et-cles.md).');

        return self::SUCCESS;
    }
}
