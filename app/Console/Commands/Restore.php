<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Restauration complète : les données actuelles sont REMPLACÉES par celles
 * de l'archive. Les clés (APP_KEY, SECRETS_KEY) doivent avoir été restaurées
 * au préalable dans le fichier .env.
 */
class Restore extends Command
{
    protected $signature = 'techindex:restore
        {archive : Chemin de l\'archive (.zip ou .zip.age)}
        {--identity= : Fichier de clé privée age pour une archive chiffrée}
        {--ignore-keys : Restaure même si les clés ne correspondent pas (secrets illisibles)}
        {--force : Ne demande pas de confirmation}';

    protected $description = 'Restaure une sauvegarde (remplace les données actuelles)';

    public function handle(BackupService $backups): int
    {
        if (! $this->option('force') && ! confirm('Toutes les données actuelles seront remplacées par celles de la sauvegarde. Continuer ?', default: false)) {
            $this->components->warn('Restauration annulée.');

            return self::FAILURE;
        }

        try {
            $result = $backups->restore((string) $this->argument('archive'), $this->option('identity'), (bool) $this->option('ignore-keys'));
        } catch (Throwable $exception) {
            $this->components->error('Restauration impossible : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sauvegarde du '.$result['created_at'].' restaurée.');
        $this->components->twoColumnDetail('Tables restaurées', (string) count($result['tables']));
        $this->components->twoColumnDetail('Fichiers restaurés', (string) $result['files']);
        $this->components->twoColumnDetail('Clés', $result['keys']['detail']);
        $this->components->twoColumnDetail('Secrets déchiffrables', ($result['secrets']['total'] - $result['secrets']['failed']).' / '.$result['secrets']['total']);

        return $result['secrets']['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
