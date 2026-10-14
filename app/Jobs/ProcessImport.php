<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Audit\AuditLogger;
use App\Services\Import\ImportProcessor;
use App\Services\Import\ImportRejectedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Exécution d'un import en file d'attente (worker Laravel, file "database").
 * Tout ou rien : en cas d'erreur, la transaction est annulée et le rapport
 * indique qu'aucune donnée n'a été enregistrée.
 */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $importId) {}

    public function handle(ImportProcessor $processor, AuditLogger $audit): void
    {
        $import = Import::query()->with(['user', 'client'])->find($this->importId);

        if ($import === null || $import->status !== ImportStatus::Queued) {
            return;
        }

        $import->forceFill(['status' => ImportStatus::Processing, 'started_at' => now()])->save();
        $user = $import->user;

        try {
            if ($user === null || ! $user->is_active || ! $user->can('execute', $import)) {
                throw new ImportRejectedException('L\'auteur de l\'import n\'a plus les droits nécessaires.');
            }

            $counts = $processor->execute($import, $user);

            $import->forceFill([
                'status' => ImportStatus::Completed,
                'created_count' => $counts['created'],
                'updated_count' => $counts['updated'],
                'skipped_count' => $counts['skipped'],
                'error_count' => $counts['errors'],
                'finished_at' => now(),
            ])->save();

            $audit->log('import.completed', $import, metadata: [
                'type' => $import->type->label(),
                'fichier' => $import->original_filename,
                'créés' => $counts['created'],
                'mis à jour' => $counts['updated'],
                'ignorés' => $counts['skipped'],
                'lignes en erreur ignorées' => $counts['errors'],
            ], clientId: $import->client_id, label: $import->original_filename, user: $user);
        } catch (Throwable $exception) {
            if (! $exception instanceof ImportRejectedException) {
                report($exception);
            }

            $import->forceFill([
                'status' => ImportStatus::Failed,
                'failure_reason' => $exception instanceof ImportRejectedException
                    ? $exception->getMessage().' Aucune donnée n\'a été enregistrée.'
                    : 'Erreur inattendue pendant l\'import : aucune donnée n\'a été enregistrée. Consultez les journaux du serveur.',
                'finished_at' => now(),
            ])->save();

            $audit->log('import.failed', $import, metadata: ['fichier' => $import->original_filename], clientId: $import->client_id, label: $import->original_filename, user: $user);
        }
    }
}
