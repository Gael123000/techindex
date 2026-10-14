<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * Test de restauration : l'archive est restaurée dans une base temporaire et
 * contrôlée, sans toucher aux données en service. Une sauvegarde n'est
 * considérée comme valide qu'après ce test (ou une restauration réelle).
 */
class BackupVerify extends Command
{
    protected $signature = 'techindex:backup-verify
        {archive : Chemin de l\'archive (.zip ou .zip.age)}
        {--identity= : Fichier de clé privée age pour une archive chiffrée}';

    protected $description = 'Teste la restauration d\'une sauvegarde dans une base temporaire';

    public function handle(BackupService $backups): int
    {
        $result = $backups->verify((string) $this->argument('archive'), $this->option('identity'));

        foreach ($result['checks'] as $check) {
            $this->components->twoColumnDetail(
                $check['label'].($check['detail'] !== '' ? ' <fg=gray>('.$check['detail'].')</>' : ''),
                $check['ok'] ? '<fg=green>OK</>' : '<fg=red>ÉCHEC</>',
            );
        }

        $this->newLine();

        if ($result['ok']) {
            $this->components->info('Restauration de test réussie : la sauvegarde est valide.');

            return self::SUCCESS;
        }

        $this->components->error('La restauration de test a échoué : cette sauvegarde ne doit pas être considérée comme valide.');

        return self::FAILURE;
    }
}
