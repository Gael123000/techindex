<?php

namespace App\Console\Commands;

use App\Services\Updates\UpdateManager;
use Illuminate\Console\Command;

/**
 * Recherche la dernière version publiée sur le canal officiel (manifeste
 * signé). Planifiée chaque jour ; le résultat s'affiche dans l'administration.
 */
class UpdateCheck extends Command
{
    protected $signature = 'techindex:update-check';

    protected $description = 'Recherche une nouvelle version de TechIndex (canal officiel signé)';

    public function handle(UpdateManager $updates): int
    {
        $result = $updates->check();

        $this->components->twoColumnDetail('Version installée', $updates->installedVersion());

        if ($result['error'] !== null) {
            $this->components->error($result['error']);

            return self::FAILURE;
        }

        $latest = (array) $result['latest'];
        $this->components->twoColumnDetail('Dernière version publiée', (string) $latest['version']);

        if ($result['available']) {
            $this->components->twoColumnDetail('Modifie la base de données', $latest['migrations'] ? 'oui' : 'non');
            $this->components->info('Mise à jour disponible.');
        } else {
            $this->components->info('TechIndex est à jour.');
        }

        return self::SUCCESS;
    }
}
