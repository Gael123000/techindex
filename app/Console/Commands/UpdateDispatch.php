<?php

namespace App\Console\Commands;

use App\Services\Updates\UpdateManager;
use Illuminate\Console\Command;

/**
 * Hébergement Plesk : lance la mise à jour demandée depuis l'administration
 * (exécutée chaque minute par le planificateur), et enregistre dans l'audit
 * l'issue de la précédente.
 */
class UpdateDispatch extends Command
{
    protected $signature = 'techindex:update-dispatch';

    protected $description = 'Lance une mise à jour demandée depuis l\'administration (Plesk)';

    public function handle(UpdateManager $updates): int
    {
        $version = $updates->dispatch();

        if ($version !== null) {
            $this->components->info("Mise à jour vers la version {$version} lancée.");
        }

        return self::SUCCESS;
    }
}
