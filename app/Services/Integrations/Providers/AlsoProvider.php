<?php

namespace App\Services\Integrations\Providers;

use App\Enums\BillingPeriod;
use App\Enums\IntegrationCategory;
use App\Enums\LicenseStatus;
use App\Models\IntegrationAccount;
use App\Services\Integrations\Data\ExternalAccount;
use App\Services\Integrations\Data\ExternalLicense;
use App\Services\Integrations\IntegrationContext;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\ProviderDefinition;
use App\Services\Integrations\Support\ServiceUrl;
use Illuminate\Http\Client\PendingRequest;

/**
 * ALSO Cloud Marketplace (SimpleAPI) : sociétés clientes et abonnements.
 * L'API s'utilise avec un compte utilisateur de la place de marché, sans
 * double authentification (limitation de l'API).
 */
class AlsoProvider extends AbstractProvider
{
    public const KEY = 'also';

    private const ALLOWED_HOSTS = [
        'also.com', 'also.fr', 'also.ch', 'also.de', 'also.at', 'also.nl', 'also.be', 'also.eu',
        'also.dk', 'also.se', 'also.no', 'also.fi', 'also.pl', 'also.it', 'also.es', 'also.lu',
    ];

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'ALSO Cloud Marketplace',
            vendor: 'ALSO',
            category: IntegrationCategory::Distributor,
            description: 'Sociétés clientes et abonnements de la place de marché ALSO : service, éditeur, quantité, fin de contrat.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::text('username', 'Utilisateur de la place de marché'),
                ProviderDefinition::secret('password', 'Mot de passe'),
            ],
            settings: [
                ProviderDefinition::text('base_url', 'Adresse de la place de marché', placeholder: 'https://marketplace.also.fr', help: 'Adresse de connexion à votre place de marché ALSO (pays).', allowedHosts: self::ALLOWED_HOSTS),
            ],
            setup: [
                'Dans la place de marché ALSO, créez un utilisateur dédié à TechIndex, sans double authentification (non prise en charge par l\'API).',
                'Indiquez ici l\'adresse de votre place de marché, l\'utilisateur et son mot de passe.',
            ],
            docsUrl: 'https://app.swaggerhub.com/apis/MarketplaceSimpleAPI/MarketplaceSimpleAPI/1.0.0',
            initials: 'AL',
            color: '#e2001a',
            beta: true,
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $company = $this->ownCompany($context);

        return 'Connexion réussie au compte « '.($this->string($company['CompanyName'] ?? null) ?? 'ALSO').' ».';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        $parentId = $this->ownCompany($context)['AccountId'] ?? null;

        if (! is_numeric($parentId)) {
            throw IntegrationException::invalidResponse('Lecture du compte revendeur');
        }

        foreach ($this->call($context, 'GetCompanies', ['parentAccountId' => (int) $parentId], 'Lecture des sociétés') as $company) {
            $id = is_array($company) ? $this->string($company['AccountId'] ?? null) : null;

            if ($id === null || ($company['AccountState'] ?? 'Active') === 'Terminated') {
                continue;
            }

            $domains = is_array($company['Domain'] ?? null) ? $company['Domain'] : [];

            yield new ExternalAccount($id, $this->string($company['CompanyName'] ?? null) ?? $id, $this->string($domains[0] ?? null), [
                'Ville' => $this->string($company['City'] ?? null),
                'Pays' => $this->string($company['Country'] ?? null),
            ]);
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        foreach ($this->call($context, 'GetSubscriptions', ['parentAccountId' => (int) $account->external_id], 'Lecture des abonnements') as $subscription) {
            $id = is_array($subscription) ? $this->string($subscription['AccountId'] ?? null) : null;

            if ($id === null) {
                continue;
            }

            $state = strtolower((string) ($subscription['AccountState'] ?? $subscription['Status'] ?? 'active'));
            $priceable = collect($subscription['PriceableItems'] ?? [])->first(fn ($item): bool => is_array($item)) ?? [];
            $months = (int) ($priceable['CommitementPeriodInMonths'] ?? 0);

            yield new ExternalLicense(
                externalId: $id,
                name: $this->string($subscription['ServiceDisplayName'] ?? null) ?? $this->string($subscription['ServiceName'] ?? null) ?? 'Abonnement',
                publisher: $this->string($subscription['VendorDisplayName'] ?? null, 150),
                quantity: $this->quantity($subscription['Fields'] ?? []),
                status: in_array($state, ['terminated', 'cancelled', 'canceled', 'suspended'], true) ? LicenseStatus::Cancelled : LicenseStatus::Active,
                startsAt: $this->date($subscription['BillingStartDate'] ?? null),
                expiresAt: $this->date($subscription['ContractEndDate'] ?? $subscription['ScheduledTerminationDate'] ?? null),
                billingPeriod: match (true) {
                    $months === 1 => BillingPeriod::Monthly,
                    $months === 12 => BillingPeriod::Yearly,
                    $months > 12 => BillingPeriod::MultiYear,
                    default => null,
                },
                reference: $this->string($subscription['VendorReferenceId'] ?? null) ?? $id,
            );
        }
    }

    /**
     * Quantité, rangée parmi les champs de l'abonnement (nom selon le service).
     */
    private function quantity(mixed $fields): ?int
    {
        foreach (is_array($fields) ? $fields : [] as $field) {
            $name = strtolower((string) ($field['Name'] ?? '').' '.($field['DisplayName'] ?? ''));

            if (preg_match('/quant|licen|seat|user|anzahl|nombre/', $name) === 1 && is_numeric($field['Value'] ?? null)) {
                return max(0, (int) $field['Value']);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function ownCompany(IntegrationContext $context): array
    {
        $json = $this->call($context, 'GetCompany', new \stdClass, 'Lecture du compte revendeur');
        $company = array_is_list($json) ? ($json[0] ?? null) : $json;

        return is_array($company) ? $company : throw IntegrationException::invalidResponse('Lecture du compte revendeur');
    }

    /**
     * @param  array<string, mixed>|\stdClass  $body
     * @return array<int|string, mixed>
     */
    private function call(IntegrationContext $context, string $method, array|\stdClass $body, string $what): array
    {
        return $this->json($context, $what, fn () => $this->request($context)->post($this->endpoint($context, $method), $body));
    }

    private function endpoint(IntegrationContext $context, string $method): string
    {
        return ServiceUrl::https((string) $context->setting('base_url'), self::ALLOWED_HOSTS, 'Adresse de la place de marché ALSO')
            .'/SimpleAPI/SimpleAPIService.svc/rest/'.$method;
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $context->token('also', function () use ($context): array {
            $response = $context->guard('Connexion à ALSO', fn () => $context->http()->post($this->endpoint($context, 'GetSessionToken'), [
                'username' => $context->credential('username'),
                'password' => $context->credential('password'),
            ]));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, 'Connexion à ALSO');
            }

            // Réponse : le jeton seul, en chaîne JSON.
            $token = $response->json() ?? trim($response->body(), "\" \n\r\t");

            return is_string($token) && $token !== '' && strlen($token) < 2000
                ? [$token, 1800]
                : throw IntegrationException::invalidResponse('Connexion à ALSO');
        });

        return $context->http()->withHeaders(['Authenticate' => 'CCPSessionId '.$token]);
    }
}
