<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\AuditLog;
use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge planifiée :
 * - fichiers d'import au-delà de IMPORT_RETENTION_DAYS (le rapport est conservé) ;
 * - imports abandonnés (jamais exécutés) au-delà du même délai ;
 * - journal d'audit au-delà de AUDIT_RETENTION_DAYS si une durée est fixée.
 */
class PruneData extends Command
{
    protected $signature = 'techindex:prune';

    protected $description = 'Supprime les fichiers d\'import anciens et, si configuré, l\'audit ancien';

    public function handle(): int
    {
        $limit = now()->subDays((int) config('techindex.imports.retention_days'));
        $disk = Storage::disk('local');
        $files = 0;

        Import::query()->whereNotNull('stored_path')->where('created_at', '<', $limit)->each(function (Import $import) use ($disk, &$files): void {
            $disk->delete($import->stored_path);
            $import->forceFill(['stored_path' => null])->save();
            $files++;
        });

        $abandoned = Import::query()
            ->whereIn('status', [ImportStatus::Uploaded, ImportStatus::Validated])
            ->where('created_at', '<', $limit)
            ->delete();

        $this->components->info("{$files} fichier(s) d'import supprimé(s), {$abandoned} import(s) abandonné(s) supprimé(s).");

        $retention = (int) config('techindex.audit.retention_days');

        if ($retention > 0) {
            $deleted = AuditLog::query()->where('created_at', '<', now()->subDays($retention))->delete();
            $this->components->info("{$deleted} entrée(s) d'audit de plus de {$retention} jours supprimée(s).");
        }

        // Fichiers temporaires de lecture des classeurs Excel.
        foreach (glob(storage_path('app/private/tmp/*')) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < now()->subDay()->getTimestamp()) {
                @unlink($file);
            }
        }

        return self::SUCCESS;
    }
}
