<?php

namespace App\Console\Commands;

use App\Secrets\Keyring;
use Illuminate\Console\Command;

/**
 * Affiche les EMPREINTES (jamais les valeurs) d'APP_KEY et de la clé des
 * secrets. Les scripts de sauvegarde les enregistrent dans le manifeste ;
 * la restauration vérifie que les clés installées correspondent.
 */
class KeysInfo extends Command
{
    protected $signature = 'techindex:keys-info {--json : Sortie JSON}';

    protected $description = 'Affiche les empreintes des clés de chiffrement (sans les révéler)';

    public function handle(Keyring $keyring): int
    {
        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7)) : $appKey;

        $info = [
            'app_key' => $appKey === '' ? null : substr(hash('sha256', 'techindex-app-key|'.$raw), 0, 16),
            'secrets_key' => $keyring->isConfigured() ? $keyring->currentId() : null,
            'secrets_previous_keys' => $keyring->isConfigured() ? array_slice($keyring->fingerprints(), 1) : [],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($info));
        } else {
            $this->components->twoColumnDetail('Empreinte APP_KEY', $info['app_key'] ?? '<fg=red>absente</>');
            $this->components->twoColumnDetail('Empreinte clé des secrets', $info['secrets_key'] ?? '<fg=red>absente</>');

            foreach ($info['secrets_previous_keys'] as $fingerprint) {
                $this->components->twoColumnDetail('  ancienne clé acceptée', $fingerprint);
            }
        }

        return $info['app_key'] !== null && $info['secrets_key'] !== null ? self::SUCCESS : self::FAILURE;
    }
}
