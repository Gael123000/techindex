<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Integration;
use App\Models\IntegrationAccount;
use App\Models\License;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\AccountMatcher;
use App\Services\Integrations\Contracts\VerifiesAccounts;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\IntegrationSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Comptes externes d'une intégration (clients du distributeur, locataires
 * Microsoft 365...) et leur rattachement aux clients TechIndex.
 */
class IntegrationAccountController extends Controller
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ajout manuel d'un compte (connecteurs à consentement par locataire).
     */
    public function store(Request $request, string $provider, IntegrationSync $sync): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $implementation = $this->registry->get($provider);
        abort_unless($implementation instanceof VerifiesAccounts, 404);
        $integration = Integration::query()->where('provider', $provider)->firstOrFail();

        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
        ], [], ['identifier' => mb_strtolower($implementation->accountInput()['label']), 'client_id' => 'client']);

        try {
            $external = $implementation->verifyAccount($sync->context($integration), $data['identifier']);
        } catch (IntegrationException $exception) {
            return back()->withErrors(['identifier' => $exception->getMessage()]);
        }

        $sync->rememberAccount($integration, $external, manual: true);
        $account = $integration->accounts()->where('external_id', $external->externalId)->firstOrFail();
        $this->audit->log('integration.account_added', $integration, metadata: ['compte' => $account->name], label: $implementation->definition()->label);

        if (($data['client_id'] ?? null) !== null) {
            $this->link($account, (int) $data['client_id']);
        }

        return back()->with('success', "Compte « {$account->name} » ajouté.");
    }

    public function update(Request $request, string $provider, IntegrationAccount $account): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $this->ensureBelongs($provider, $account);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'is_ignored' => ['boolean'],
        ]);

        if (array_key_exists('is_ignored', $data)) {
            $account->forceFill(['is_ignored' => $data['is_ignored']])->save();
        }

        if (array_key_exists('client_id', $data) && $data['client_id'] !== $account->client_id) {
            $this->link($account, $data['client_id']);
        }

        return back()->with('success', 'Compte mis à jour.');
    }

    /**
     * Rattache automatiquement les comptes dont le client est évident.
     */
    public function match(string $provider, AccountMatcher $matcher): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $this->registry->get($provider);
        $integration = Integration::query()->where('provider', $provider)->firstOrFail();
        $linked = 0;

        foreach ($integration->accounts()->whereNull('client_id')->where('is_ignored', false)->get() as $account) {
            $clientId = $matcher->suggest($account);

            if ($clientId !== null) {
                $this->link($account, $clientId);
                $linked++;
            }
        }

        return back()->with($linked > 0 ? 'success' : 'error', $linked > 0
            ? "{$linked} compte(s) rattaché(s) automatiquement. Vérifiez les rapprochements, puis synchronisez."
            : 'Aucun rapprochement évident : choisissez le client de chaque compte dans la liste.');
    }

    public function destroy(string $provider, IntegrationAccount $account): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $this->ensureBelongs($provider, $account);

        DB::transaction(function () use ($account): void {
            // Les licences remontées restent chez le client, comme des licences saisies à la main.
            License::query()->where('integration_account_id', $account->id)->update(['integration_account_id' => null, 'external_id' => null]);
            $account->delete();
        });

        $this->audit->log('integration.account_removed', $account->integration, metadata: ['compte' => $account->name], clientId: $account->client_id, label: $this->registry->label($provider));

        return back()->with('success', "Compte « {$account->name} » retiré de l'intégration.");
    }

    /**
     * Change le client d'un compte. Ses licences suivent le nouveau client
     * (sans le site ni les équipements de l'ancien) ; sans client, elles
     * restent chez l'ancien comme des licences saisies à la main.
     */
    private function link(IntegrationAccount $account, ?int $clientId): void
    {
        $account->loadMissing('integration');
        $previous = $account->client_id;
        $label = $this->registry->label($account->integration->provider);

        DB::transaction(function () use ($account, $clientId): void {
            $licenses = License::query()->where('integration_account_id', $account->id);

            if ($clientId === null) {
                $licenses->update(['integration_account_id' => null, 'external_id' => null]);
            } else {
                $ids = $licenses->pluck('id');
                License::query()->whereIn('id', $ids)->update(['client_id' => $clientId, 'site_id' => null]);
                DB::table('equipment_license')->whereIn('license_id', $ids)->delete();
            }

            $account->forceFill(['client_id' => $clientId, 'is_ignored' => false])->save();
        });

        $client = $clientId === null ? null : Client::query()->find($clientId, ['id', 'name']);

        if ($previous !== null) {
            $this->audit->log('integration.account_unlinked', $account->integration, metadata: ['compte' => $account->name], clientId: $previous, label: $label);
        }

        if ($client !== null) {
            $this->audit->log('integration.account_linked', $account->integration, metadata: ['compte' => $account->name, 'client' => $client->name], clientId: $client->id, label: $label);
        }
    }

    private function ensureBelongs(string $provider, IntegrationAccount $account): void
    {
        $this->registry->get($provider);
        abort_unless($account->loadMissing('integration')->integration?->provider === $provider, 404);
    }
}
