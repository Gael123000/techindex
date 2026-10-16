<?php

namespace App\Console\Commands;

use App\Enums\IntegrationRunStatus;
use App\Models\Integration;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\IntegrationSync;
use App\Services\Integrations\Support\MicrosoftSkuCatalog;
use Illuminate\Console\Command;

/**
 * Synchronise les intégrations arrivées à échéance (planifiée toutes les
 * heures, en arrière-plan). Les synchronisations s'exécutent directement,
 * l'une après l'autre, sans passer par la file d'attente. Avec
 * --refresh-catalogs, la liste officielle des noms de produits Microsoft
 * est aussi mise à jour.
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
        $done = 0;

        $integrations = Integration::query()
            ->where('is_enabled', true)
            ->when($provider, fn ($query, string $key) => $query->where('provider', $key))
            ->get();

        foreach ($integrations as $integration) {
            if (! $registry->has($integration->provider)) {
                continue;
            }

            $sync->expireStalled($integration);

            if ($provider === null && ! $integration->frequency->isDue($integration->last_synced_at, now())) {
                continue;
            }

            // Une synchronisation déjà en cours (lancée depuis l'administration) n'est pas doublée.
            if ($integration->runs()->whereIn('status', [IntegrationRunStatus::Queued, IntegrationRunStatus::Running])->exists()) {
                continue;
            }

            $run = $sync->run($sync->start($integration, 'scheduled'));
            $this->components->twoColumnDetail($registry->label($integration->provider), $run->status->label());
            $done++;
        }

        $this->components->info("{$done} synchronisation(s) effectuée(s).");

        return self::SUCCESS;
    }
}
