<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class Backup extends Command
{
    protected $signature = 'techindex:backup';

    protected $description = 'Sauvegarde la base de données, les pièces jointes et les paramètres (sans les clés)';

    public function handle(BackupService $backups): int
    {
        try {
            $result = $backups->create();
        } catch (Throwable $exception) {
            $this->components->error('Échec de la sauvegarde : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sauvegarde créée : '.$result['path'].' ('.round($result['size'] / 1024 / 1024, 2).' Mo)');
        $this->components->twoColumnDetail('Chiffrée (age)', $result['encrypted'] ? 'oui' : '<fg=yellow>non – définissez BACKUP_AGE_RECIPIENT</>');
        $this->components->twoColumnDetail('Pièces jointes et logos', (string) count($result['manifest']['files']));
        $this->components->twoColumnDetail('Empreinte APP_KEY', (string) $result['manifest']['keys']['app_key']);
        $this->components->twoColumnDetail('Empreinte clé des secrets', (string) $result['manifest']['keys']['secrets_key']);
        $this->newLine();
        $this->line('  Les clés de chiffrement ne sont pas dans l\'archive : vérifiez qu\'elles sont sauvegardées à part.');
        $this->line('  Copiez l\'archive hors du serveur, puis validez-la : php artisan techindex:backup-verify <archive>');

        return self::SUCCESS;
    }
}
