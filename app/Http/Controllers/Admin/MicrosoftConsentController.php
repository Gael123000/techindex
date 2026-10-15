<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Integration;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\IntegrationSync;
use App\Services\Integrations\Providers\Microsoft365Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Consentement administrateur Microsoft 365 : l'administrateur ouvre le lien
 * depuis TechIndex, se connecte avec un administrateur général du locataire
 * du client, puis revient ici ; le locataire est vérifié (jeton et lecture de
 * l'organisation) avant d'être ajouté.
 */
class MicrosoftConsentController extends Controller
{
    private const SESSION_KEY = 'integrations.microsoft365.consent';

    public function redirect(Request $request, IntegrationRegistry $registry, IntegrationSync $sync): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $integration = Integration::query()->where('provider', Microsoft365Provider::KEY)->firstOrFail();
        /** @var Microsoft365Provider $provider */
        $provider = $registry->get(Microsoft365Provider::KEY);

        if (blank($integration->setting('client_id'))) {
            return redirect()->route('admin.integrations.show', Microsoft365Provider::KEY)->with('error', 'Renseignez d\'abord l\'ID d\'application.');
        }

        // Client à rattacher au retour (facultatif).
        $clientId = $request->integer('client') ?: null;
        $state = Str::random(48);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'client_id' => $clientId, 'expires' => now()->addMinutes(30)->getTimestamp()]);

        return redirect()->away($provider->consentUrl($sync->context($integration), route('admin.integrations.microsoft.callback'), $state));
    }

    public function callback(Request $request, IntegrationRegistry $registry, IntegrationSync $sync, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $back = redirect()->route('admin.integrations.show', Microsoft365Provider::KEY);
        $pending = $request->session()->pull(self::SESSION_KEY);

        // Le paramètre « state » protège contre un retour forgé (CSRF).
        if (! is_array($pending) || ! is_string($request->query('state')) || ! hash_equals($pending['state'], $request->query('state')) || $pending['expires'] < now()->getTimestamp()) {
            return $back->with('error', 'Retour de consentement invalide ou expiré : relancez le lien de consentement.');
        }

        if ($request->filled('error')) {
            $code = preg_match('/^[a-z_]{2,40}$/', (string) $request->query('error')) === 1 ? ' ('.$request->query('error').')' : '';

            return $back->with('error', 'Consentement refusé ou annulé'.$code.'. Il doit être donné par un administrateur général du locataire.');
        }

        $tenant = (string) $request->query('tenant');

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant) !== 1) {
            return $back->with('error', 'Identifiant de locataire absent du retour de Microsoft.');
        }

        $integration = Integration::query()->where('provider', Microsoft365Provider::KEY)->firstOrFail();
        /** @var Microsoft365Provider $provider */
        $provider = $registry->get(Microsoft365Provider::KEY);

        // Le locataire renvoyé n'est pas une preuve : l'accès est vérifié avant l'ajout.
        try {
            $external = $provider->verifyAccount($sync->context($integration), $tenant);
        } catch (IntegrationException $exception) {
            return $back->with('error', 'Consentement reçu, mais le locataire n\'est pas encore accessible : '.$exception->getMessage().' Réessayez dans quelques minutes avec « Ajouter un locataire ».');
        }

        $sync->rememberAccount($integration, $external, manual: true);
        $account = $integration->accounts()->where('external_id', $external->externalId)->firstOrFail();
        $audit->log('integration.account_added', $integration, metadata: ['compte' => $account->name, 'consentement' => 'oui'], label: 'Microsoft 365');

        $clientId = $pending['client_id'] ?? null;

        if ($clientId !== null && $account->client_id === null && Client::query()->whereKey($clientId)->exists()) {
            $account->forceFill(['client_id' => $clientId])->save();
            $audit->log('integration.account_linked', $integration, metadata: ['compte' => $account->name], clientId: $clientId, label: 'Microsoft 365');
        }

        return $back->with('success', "Locataire « {$account->name} » autorisé et ajouté.");
    }
}
