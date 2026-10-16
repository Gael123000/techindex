<?php

namespace App\Jobs;

use App\Enums\IntegrationRunStatus;
use App\Models\IntegrationRun;
use App\Services\Integrations\IntegrationSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Synchronisation d'une intégration. Lancée depuis l'administration, elle
 * s'exécute après l'envoi de la réponse (dispatchAfterResponse) ; elle peut
 * aussi passer par la file d'attente. Une exécution qui ne démarre pas ou
 * s'interrompt est signalée par IntegrationSync::expireStalled().
 */
class SyncIntegration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $runId, public readonly int $integrationId) {}

    public function handle(IntegrationSync $sync): void
    {
        $run = IntegrationRun::query()->with(['integration', 'user'])->find($this->runId);

        if ($run === null || $run->status !== IntegrationRunStatus::Queued || $run->integration === null) {
            return;
        }

        // Après la réponse, dans le processus web : la synchronisation ne doit
        // pas être coupée par la limite d'exécution ni par la fermeture de la page.
        ignore_user_abort(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $sync->run($run);
    }

    /**
     * Échec de la tâche elle-même (file d'attente) : l'exécution ne reste pas « en attente ».
     */
    public function failed(?Throwable $exception): void
    {
        IntegrationRun::query()
            ->whereKey($this->runId)
            ->whereIn('status', [IntegrationRunStatus::Queued, IntegrationRunStatus::Running])
            ->update(['status' => IntegrationRunStatus::Failed, 'message' => 'Synchronisation interrompue (erreur du traitement de fond, détails dans les journaux du serveur).', 'finished_at' => now()]);
    }
}
