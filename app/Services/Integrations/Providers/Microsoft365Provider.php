<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationCategory;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use App\Models\IntegrationAccount;
use App\Services\Integrations\Contracts\VerifiesAccounts;
use App\Services\Integrations\Data\ExternalAccount;
use App\Services\Integrations\Data\ExternalLicense;
use App\Services\Integrations\IntegrationContext;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\ProviderDefinition;
use App\Services\Integrations\Support\MicrosoftSkuCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;

/**
 * Microsoft 365 via Microsoft Graph : une application Entra ID
 * multi-locataire du prestataire, autorisée par consentement administrateur
 * dans le locataire de chaque client (permission d'application
 * Organization.Read.All). Les licences remontent avec le nombre de licences
 * achetées et attribuées.
 */
class Microsoft365Provider extends AbstractProvider implements VerifiesAccounts
{
    public const KEY = 'microsoft365';

    private const LOGIN = 'https://login.microsoftonline.com';

    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Licences gratuites ou virales (Power Automate Free...) : 10 000 unités et plus. */
    private const FREE_THRESHOLD = 10000;

    public function __construct(private readonly MicrosoftSkuCatalog $catalog) {}

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Microsoft 365',
            vendor: 'Microsoft',
            category: IntegrationCategory::Cloud,
            description: 'Licences Microsoft 365 de chaque locataire client : produits, licences achetées et attribuées, état et échéance.',
            accountLabel: 'locataire',
            credentials: [
                ProviderDefinition::secret('client_secret', 'Secret client', help: 'Valeur du secret créé dans « Certificats et secrets » (notez sa date d\'expiration).'),
            ],
            settings: [
                ProviderDefinition::text('client_id', 'ID d\'application (client)', placeholder: '00000000-0000-0000-0000-000000000000'),
                ProviderDefinition::text('home_tenant', 'Votre locataire (prestataire)', help: 'Identifiant ou domaine du locataire où l\'application est inscrite : sert au test de connexion.', placeholder: 'votre-societe.onmicrosoft.com'),
                ProviderDefinition::select('skip_free', 'Licences gratuites', ['yes' => 'Ignorer (Power Automate Free, Teams Exploratory…)', 'no' => 'Les importer aussi'], 'yes'),
            ],
            setup: [
                'Dans le centre d\'administration Microsoft Entra de votre société : Inscriptions d\'applications › Nouvelle inscription, type « Comptes dans un annuaire d\'organisation (multilocataire) ».',
                'URI de redirection (Web) : l\'adresse de retour affichée ci-dessous.',
                'Autorisations des API › Microsoft Graph › Autorisations d\'application › Organization.Read.All, puis « Accorder un consentement d\'administrateur » pour votre locataire.',
                'Certificats et secrets › Nouveau secret client : recopiez sa valeur ici, avec l\'ID d\'application.',
                'Pour chaque client : ouvrez le lien de consentement et connectez-vous avec un administrateur général du locataire du client (ou envoyez-lui le lien), puis ajoutez le locataire s\'il n\'apparaît pas automatiquement.',
            ],
            docsUrl: 'https://learn.microsoft.com/fr-fr/graph/api/subscribedsku-list',
            initials: 'M365',
            color: '#d83b01',
        );
    }

    public function accountInput(): array
    {
        return [
            'label' => 'Identifiant ou domaine du locataire',
            'placeholder' => 'client.onmicrosoft.com',
            'help' => 'Le consentement administrateur doit déjà avoir été donné dans ce locataire.',
        ];
    }

    /**
     * Lien de consentement administrateur (à ouvrir avec un administrateur
     * général du locataire du client).
     */
    public function consentUrl(IntegrationContext $context, string $redirectUri, string $state): string
    {
        return self::LOGIN.'/organizations/v2.0/adminconsent?'.http_build_query([
            'client_id' => $context->setting('client_id'),
            'scope' => 'https://graph.microsoft.com/.default',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function testConnection(IntegrationContext $context): string
    {
        $organization = $this->organization($context, (string) $context->setting('home_tenant'));

        return 'Connexion réussie à « '.$organization->name.' ». Ajoutez maintenant les locataires de vos clients.';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        // Comptes ajoutés à la main (consentement par locataire).
        return [];
    }

    public function verifyAccount(IntegrationContext $context, string $identifier): ExternalAccount
    {
        $identifier = trim($identifier);

        if (preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[a-z0-9.-]+\.[a-z]{2,})$/i', $identifier) !== 1) {
            throw new IntegrationException('Saisissez l\'identifiant (GUID) ou un domaine du locataire, par exemple client.onmicrosoft.com.');
        }

        return $this->organization($context, $identifier);
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $token = $this->token($context, $account->external_id);
        $request = $context->http()->withToken($token);
        $skipFree = $context->setting('skip_free') !== 'no';

        $skus = $this->paginate($context, $request, self::GRAPH.'/subscribedSkus', 'Lecture des licences');
        $dates = $this->subscriptionDates($context, $request);

        foreach ($skus as $sku) {
            $partNumber = $this->string($sku['skuPartNumber'] ?? null) ?? '';
            $skuId = $this->string($sku['skuId'] ?? null) ?? $partNumber;
            $enabled = $this->int($sku['prepaidUnits']['enabled'] ?? null) ?? 0;
            $warning = $this->int($sku['prepaidUnits']['warning'] ?? null) ?? 0;

            if ($skuId === '' || ($skipFree && $enabled >= self::FREE_THRESHOLD)) {
                continue;
            }

            $state = (string) ($sku['capabilityStatus'] ?? 'Enabled');
            $subscription = $dates[mb_strtolower($skuId)] ?? null;

            yield new ExternalLicense(
                externalId: $skuId,
                name: $this->catalog->name($partNumber, $skuId).(($subscription['trial'] ?? false) ? ' (essai)' : ''),
                publisher: 'Microsoft',
                quantity: $enabled + $warning,
                assigned: $this->int($sku['consumedUnits'] ?? null),
                // Suspendu, verrouillé ou supprimé : les licences ne sont plus utilisables.
                status: in_array($state, ['Enabled', 'Warning'], true) ? LicenseStatus::Active : LicenseStatus::Cancelled,
                startsAt: $subscription['start'] ?? null,
                expiresAt: $subscription['next'] ?? null,
                reference: $partNumber !== '' ? $partNumber : null,
                kind: LicenseKind::Subscription,
            );
        }
    }

    /**
     * Dates des abonnements du locataire, regroupées par produit (première
     * souscription, prochaine échéance). Facultatif : ignoré si refusé.
     *
     * @return array<string, array{start: ?CarbonImmutable, next: ?CarbonImmutable, trial: bool}>
     */
    private function subscriptionDates(IntegrationContext $context, PendingRequest $request): array
    {
        try {
            $subscriptions = $this->paginate($context, $request, self::GRAPH.'/directory/subscriptions', 'Lecture des abonnements');
        } catch (IntegrationException) {
            return [];
        }

        $dates = [];

        foreach ($subscriptions as $subscription) {
            $skuId = mb_strtolower((string) ($subscription['skuId'] ?? ''));

            if ($skuId === '' || ($subscription['status'] ?? '') === 'Deleted') {
                continue;
            }

            $start = $this->date($subscription['createdDateTime'] ?? null);
            $next = $this->date($subscription['nextLifecycleDateTime'] ?? null);
            $current = $dates[$skuId] ?? ['start' => null, 'next' => null, 'trial' => true];

            $dates[$skuId] = [
                'start' => $current['start'] === null || ($start !== null && $start->lt($current['start'])) ? ($start ?? $current['start']) : $current['start'],
                'next' => $current['next'] === null || ($next !== null && $next->lt($current['next'])) ? ($next ?? $current['next']) : $current['next'],
                // Essai seulement si tous les abonnements du produit sont des essais.
                'trial' => $current['trial'] && (bool) ($subscription['isTrial'] ?? false),
            ];
        }

        return $dates;
    }

    private function organization(IntegrationContext $context, string $tenant): ExternalAccount
    {
        if ($tenant === '') {
            throw new IntegrationException('Indiquez votre locataire (identifiant ou domaine) dans la configuration.');
        }

        $json = $this->getJson($context, $context->http()->withToken($this->token($context, $tenant)), self::GRAPH.'/organization', [
            '$select' => 'id,displayName,verifiedDomains',
        ], 'Lecture du locataire');

        $organization = $json['value'][0] ?? null;

        if (! is_array($organization) || ! is_string($organization['id'] ?? null)) {
            throw IntegrationException::invalidResponse('Lecture du locataire');
        }

        $domains = collect($organization['verifiedDomains'] ?? [])->filter(fn ($domain): bool => is_array($domain));
        $default = $domains->firstWhere('isDefault', true)['name'] ?? $domains->first()['name'] ?? null;
        $initial = $domains->firstWhere('isInitial', true)['name'] ?? null;

        return new ExternalAccount(
            externalId: $organization['id'],
            name: $this->string($organization['displayName'] ?? null) ?? $organization['id'],
            domain: $this->string($default),
            metadata: ['Domaine initial' => $this->string($initial), 'Domaines vérifiés' => $domains->count()],
        );
    }

    private function token(IntegrationContext $context, string $tenant): string
    {
        return $this->oauthToken($context, 'graph:'.$tenant, self::LOGIN.'/'.rawurlencode($tenant).'/oauth2/v2.0/token', [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $context->setting('client_id'),
            'client_secret' => $context->credential('client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
        ], 'Connexion à Microsoft Entra ID');
    }

    /**
     * Toutes les pages d'une collection Graph (@odata.nextLink).
     *
     * @return list<array<string, mixed>>
     */
    private function paginate(IntegrationContext $context, PendingRequest $request, string $url, string $what): array
    {
        $items = [];
        $query = [];

        for ($page = 0; $page < 50 && $url !== null; $page++) {
            // Le lien suivant ne peut pointer que vers Microsoft Graph.
            if (! str_starts_with($url, self::GRAPH.'/')) {
                throw IntegrationException::invalidResponse($what);
            }

            $json = $this->getJson($context, $request, $url, $query, $what);
            $items = [...$items, ...array_filter($json['value'] ?? [], 'is_array')];
            $url = is_string($json['@odata.nextLink'] ?? null) ? $json['@odata.nextLink'] : null;
            $query = [];
        }

        return $items;
    }
}
