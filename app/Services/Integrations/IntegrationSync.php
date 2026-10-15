<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationRunStatus;
use App\Enums\LicenseStatus;
use App\Models\Integration;
use App\Models\IntegrationAccount;
use App\Models\IntegrationRun;
use App\Models\License;
use App\Models\User;
use App\Secrets\Exceptions\SecretVaultException;
use App\Secrets\IntegrationSecrets;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\Data\ExternalAccount;
use App\Services\Integrations\Data\ExternalLicense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Synchronisation d'une intégration :
 *
 * 1. mise à jour de la liste des comptes (clients, locataires) du service ;
 * 2. pour chaque compte rattaché à un client TechIndex, lecture de ses
 *    licences et abonnements, ajoutés ou mis à jour dans les licences du
 *    client ; ceux qui ont disparu chez le fournisseur passent « Résiliée ».
 *
 * Les licences saisies à la main ne sont jamais modifiées. Chaque client
 * concerné reçoit une entrée d'historique récapitulative.
 */
class IntegrationSync
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationSecrets $secrets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Contexte d'exécution : identifiants déchiffrés et réglages.
     */
    public function context(Integration $integration): IntegrationContext
    {
        $definition = $this->registry->get($integration->provider)->definition();

        try {
            $credentials = $this->secrets->read($integration);
        } catch (SecretVaultException) {
            throw new IntegrationException('Les identifiants enregistrés ne peuvent pas être déchiffrés (clé des secrets modifiée ?) : saisissez-les à nouveau.');
        }

        return new IntegrationContext(
            $integration,
            $credentials,
            $integration->settings ?? [],
            $definition,
            fn (string $key, string $value) => $this->secrets->store($integration, $key, $value),
        );
    }

    public function start(Integration $integration, string $trigger, ?User $user = null): IntegrationRun
    {
        return $integration->runs()->create([
            'trigger' => $trigger,
            'user_id' => $user?->id,
            'status' => IntegrationRunStatus::Queued,
        ]);
    }

    public function run(IntegrationRun $run): IntegrationRun
    {
        $integration = $run->integration;
        $provider = $this->registry->get($integration->provider);
        $stats = ['accounts' => 0, 'new_accounts' => 0, 'synced_accounts' => 0, 'created' => 0, 'updated' => 0, 'ended' => 0, 'errors' => 0];
        $errors = [];

        $run->forceFill(['status' => IntegrationRunStatus::Running, 'started_at' => now()])->save();

        try {
            $context = $this->context($integration);

            foreach ($provider->discoverAccounts($context) as $external) {
                $stats['accounts']++;
                $stats['new_accounts'] += $this->rememberAccount($integration, $external) ? 1 : 0;
            }

            $accounts = $integration->accounts()->whereNotNull('client_id')->where('is_ignored', false)->orderBy('name')->get();

            foreach ($accounts as $account) {
                try {
                    // Lecture complète avant toute écriture : une erreur au milieu ne laisse rien à moitié.
                    $licenses = [...$provider->fetchLicenses($context, $account)];
                    $result = $this->storeLicenses($integration, $account, $licenses);

                    $stats['created'] += $result['created'];
                    $stats['updated'] += $result['updated'];
                    $stats['ended'] += $result['ended'];
                    $stats['synced_accounts']++;
                    $account->forceFill(['last_synced_at' => now(), 'last_error' => null, 'licenses_count' => count($licenses)])->save();
                } catch (IntegrationException $exception) {
                    $stats['errors']++;
                    $errors[] = $account->name.' : '.$exception->getMessage();
                    $account->forceFill(['last_error' => Str::limit($exception->getMessage(), 490)])->save();
                }
            }

            $status = match (true) {
                $stats['errors'] === 0 => IntegrationRunStatus::Success,
                $stats['synced_accounts'] > 0 => IntegrationRunStatus::Partial,
                default => IntegrationRunStatus::Failed,
            };
            $message = $errors === [] ? $this->summary($stats) : implode("\n", array_slice($errors, 0, 20));
        } catch (IntegrationException $exception) {
            $status = IntegrationRunStatus::Failed;
            $message = $exception->getMessage();
        } catch (Throwable $exception) {
            // Erreur imprévue : détail technique dans les journaux, message générique à l'écran.
            Log::error('Synchronisation de l\'intégration '.$integration->provider.' en échec', ['exception' => $exception::class, 'run' => $run->id]);
            report($exception);
            $status = IntegrationRunStatus::Failed;
            $message = 'Erreur inattendue pendant la synchronisation (détails dans les journaux du serveur).';
        }

        $run->forceFill(['status' => $status, 'stats' => $stats, 'message' => $message, 'finished_at' => now()])->save();
        $integration->forceFill([
            'last_synced_at' => now(),
            'last_status' => $status,
            'last_message' => Str::limit(strtok($message, "\n") ?: $message, 490),
        ])->saveQuietly();

        $this->audit->log('integration.synced', $integration, metadata: ['statut' => $status->label(), ...$stats], label: $this->registry->label($integration->provider), user: $run->user);

        return $run;
    }

    /**
     * Enregistre ou met à jour un compte du service ; vrai s'il est nouveau.
     */
    public function rememberAccount(Integration $integration, ExternalAccount $external, bool $manual = false): bool
    {
        $account = $integration->accounts()->firstOrNew(['external_id' => mb_substr($external->externalId, 0, 191)]);
        $isNew = ! $account->exists;

        $account->fill([
            'name' => mb_substr($external->name, 0, 255),
            'domain' => $external->domain === null ? null : mb_substr($external->domain, 0, 255),
            'metadata' => array_filter($external->metadata, fn ($value): bool => $value !== null && $value !== ''),
        ]);

        if ($isNew) {
            $account->is_manual = $manual;
        }

        $account->last_seen_at = now();
        $account->save();

        return $isNew;
    }

    /**
     * @param  list<ExternalLicense>  $licenses
     * @return array{created: int, updated: int, ended: int}
     */
    private function storeLicenses(Integration $integration, IntegrationAccount $account, array $licenses): array
    {
        $supplier = $this->registry->label($integration->provider);

        $result = DB::transaction(fn (): array => $this->audit->withoutLogging(function () use ($account, $licenses, $supplier): array {
            $counts = ['created' => 0, 'updated' => 0, 'ended' => 0];
            $seen = [];

            foreach ($licenses as $external) {
                // Colonnes de synchronisation hors affectation de masse : renseignées explicitement.
                $externalId = mb_substr($external->externalId, 0, 191);
                $license = License::query()->where('integration_account_id', $account->id)->where('external_id', $externalId)->first()
                    ?? (new License)->forceFill(['integration_account_id' => $account->id, 'external_id' => $externalId]);

                $license->forceFill([
                    'client_id' => $account->client_id,
                    'kind' => $external->kind,
                    'name' => mb_substr($external->name, 0, 255),
                    'publisher' => $external->publisher === null ? null : mb_substr($external->publisher, 0, 150),
                    'supplier' => mb_substr($supplier, 0, 150),
                    'contract_reference' => $external->reference === null ? null : mb_substr($external->reference, 0, 150),
                    'quantity' => $external->quantity,
                    'assigned_quantity' => $external->assigned,
                    'status' => $external->status,
                    'starts_at' => $external->startsAt?->toDateString(),
                    'expires_at' => $external->expiresAt?->toDateString(),
                    ...($external->billingPeriod !== null ? ['billing_period' => $external->billingPeriod] : []),
                    ...($external->autoRenewal !== null ? ['auto_renewal' => $external->autoRenewal] : []),
                ]);

                if (! $license->exists) {
                    $counts['created']++;
                } elseif ($license->isDirty()) {
                    $counts['updated']++;
                }

                // Compte rattaché à un autre client : le site et les équipements de l'ancien ne suivent pas.
                $movedClient = $license->exists && $license->isDirty('client_id');

                if ($movedClient) {
                    $license->site_id = null;
                }

                $license->synced_at = now();
                $license->save();

                if ($movedClient) {
                    $license->equipment()->detach();
                }

                $seen[] = $license->id;
            }

            // Disparues chez le fournisseur : résiliées (conservées pour l'historique).
            $counts['ended'] = License::query()
                ->where('integration_account_id', $account->id)
                ->whereNotIn('id', $seen)
                ->where('status', LicenseStatus::Active)
                ->update(['status' => LicenseStatus::Cancelled, 'synced_at' => now(), 'updated_at' => now()]);

            return $counts;
        }));

        if ($result['created'] + $result['updated'] + $result['ended'] > 0) {
            $this->audit->log('integration.licenses_synced', $integration, metadata: [
                'compte' => $account->name,
                'ajoutées' => $result['created'],
                'mises à jour' => $result['updated'],
                'résiliées' => $result['ended'],
            ], clientId: $account->client_id, label: $supplier);
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function summary(array $stats): string
    {
        return sprintf(
            '%d compte(s) synchronisé(s) : %d licence(s) ajoutée(s), %d mise(s) à jour, %d résiliée(s).',
            $stats['synced_accounts'],
            $stats['created'],
            $stats['updated'],
            $stats['ended'],
        );
    }
}
