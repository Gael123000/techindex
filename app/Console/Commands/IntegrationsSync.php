<?php

namespace App\Console\Commands;

use App\Enums\IntegrationRunStatus;
use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\IntegrationSync;
use App\Services\Integrations\Support\MicrosoftSkuCatalog;
use Illuminate\Console\Command;

/**
 * Lance les synchronisations automatiques arrivées à échéance (planifiée
 * toutes les heures). Avec --refresh-catalogs, met aussi à jour la liste
 * officielle des noms de produits Microsoft.
 */
class IntegrationsSync extends Command
{
    protected $signature = 'techindex:integrations-sync
        {--provider= : Synchronise uniquement ce connecteur, même hors échéance}
        {--refresh-catalogs : Télécharge la liste officielle des noms de licences Microsoft}';

    protected $description = 'Synchronise les intégrations (licences et abonnements)';

    public function handle(IntegrationSync $sync, IntegrationRegistry $registry, MicrosoftSkuCatalog $catalog): int
    {
        if ($this->option('refresh-catalogs')) {
            $count = $catalog->refresh();
            $count > 0
                ? $this->components->info("Liste Microsoft mise à jour : {$count} produits.")
                : $this->components->warn('Liste Microsoft non téléchargée : la liste fournie avec TechIndex reste utilisée.');
        }

        $provider = $this->option('provider');
        $queued = 0;

        $integrations = Integration::query()
            ->where('is_enabled', true)
            ->when($provider, fn ($query, string $key) => $query->where('provider', $key))
            ->get();

        foreach ($integrations as $integration) {
            if (! $registry->has($integration->provider)) {
                continue;
            }

            if ($provider === null && ! $integration->frequency->isDue($integration->last_synced_at, now())) {
                continue;
            }

            // Une synchronisation déjà en attente ou en cours n'est pas doublée.
            if ($integration->runs()->whereIn('status', [IntegrationRunStatus::Queued, IntegrationRunStatus::Running])->where('created_at', '>', now()->subHours(2))->exists()) {
                continue;
            }

            $run = $sync->start($integration, 'scheduled');
            SyncIntegration::dispatch($run->id, $integration->id);
            $queued++;
        }

        $this->components->info("{$queued} synchronisation(s) lancée(s).");

        return self::SUCCESS;
    }
}
