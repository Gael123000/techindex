<?php

namespace App\Jobs;

use App\Enums\IntegrationRunStatus;
use App\Models\IntegrationRun;
use App\Services\Integrations\IntegrationSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Synchronisation d'une intégration en file d'attente. Une seule à la fois
 * par intégration (jetons de rafraîchissement à usage unique, quotas).
 */
class SyncIntegration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** Verrou libéré au plus tard après 2 heures (tâche interrompue). */
    public int $uniqueFor = 7200;

    public function __construct(public readonly int $runId, public readonly int $integrationId) {}

    public function uniqueId(): string
    {
        return 'integration-'.$this->integrationId;
    }

    public function handle(IntegrationSync $sync): void
    {
        $run = IntegrationRun::query()->with(['integration', 'user'])->find($this->runId);

        if ($run === null || $run->status !== IntegrationRunStatus::Queued || $run->integration === null) {
            return;
        }

        $sync->run($run);
    }
}
