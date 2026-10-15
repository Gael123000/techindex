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
use Illuminate\Http\Client\PendingRequest;

/**
 * Crayon Cloud-iQ : locataires clients (tous éditeurs) et abonnements. L'API
 * exige un client d'API et un utilisateur Cloud-iQ (flux « mot de passe »),
 * dont les droits limitent ce qui est visible.
 */
class CrayonProvider extends AbstractProvider
{
    public const KEY = 'crayon';

    private const API = 'https://api.crayon.com/api/v1';

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Crayon Cloud-iQ',
            vendor: 'Crayon (SoftwareOne)',
            category: IntegrationCategory::Distributor,
            description: 'Locataires clients et abonnements gérés dans Cloud-iQ : produit, éditeur, quantité, fin d\'engagement, renouvellement.',
            accountLabel: 'locataire',
            credentials: [
                ProviderDefinition::text('client_id', 'Client ID'),
                ProviderDefinition::secret('client_secret', 'Client Secret'),
                ProviderDefinition::text('username', 'Utilisateur Cloud-iQ'),
                ProviderDefinition::secret('password', 'Mot de passe'),
            ],
            setup: [
                'Dans Cloud-iQ : API Management › ajoutez un client (flux « Resource Owner ») et notez le Client Secret, affiché une seule fois.',
                'Créez un utilisateur Cloud-iQ dédié à TechIndex ; ses droits déterminent les organisations visibles.',
            ],
            docsUrl: 'https://apidocs.crayon.com/scenarios/token-get.html',
            initials: 'CR',
            color: '#ea5b0c',
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $items = $this->items($this->getJson($context, $this->request($context), self::API.'/organizations', ['page' => 1, 'pageSize' => 100], 'Lecture des organisations'));

        return 'Connexion réussie : '.$this->count(count($items), 'organisation').' accessible'.(count($items) > 1 ? 's' : '').'.';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        foreach ($this->paginate($context, '/organizations', [], 'Lecture des organisations') as $organization) {
            $organizationId = $this->string($this->field($organization, 'Id'));

            if ($organizationId === null) {
                continue;
            }

            foreach ($this->paginate($context, '/customertenants', ['organizationId' => $organizationId], 'Lecture des locataires') as $tenant) {
                $id = $this->string($this->field($tenant, 'Id'));

                if ($id === null) {
                    continue;
                }

                $publisher = $this->field($tenant, 'Publisher');

                yield new ExternalAccount($id, $this->string($this->field($tenant, 'Name')) ?? $id, $this->string($this->field($tenant, 'DomainPrefix')), [
                    'Organisation' => $this->string($this->field($organization, 'Name')),
                    'Éditeur' => is_array($publisher) ? $this->string($this->field($publisher, 'Name')) : null,
                    'Identifiant éditeur' => $this->string($this->field($tenant, 'PublisherCustomerId')),
                ]);
            }
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        foreach ($this->paginate($context, '/subscriptions', ['customerTenantId' => $account->external_id], 'Lecture des abonnements') as $subscription) {
            $id = $this->string($this->field($subscription, 'Id'));
            $status = (int) $this->field($subscription, 'Status');

            // Supprimés (4) ou convertis (16) : remplacés par un autre abonnement.
            if ($id === null || ($status & (4 | 16)) !== 0) {
                continue;
            }

            $product = $this->field($subscription, 'Product');
            $publisher = $this->field($subscription, 'Publisher');
            $trial = (bool) $this->field($subscription, 'IsTrial');

            yield new ExternalLicense(
                externalId: $id,
                name: ($this->string($this->field($subscription, 'Name')) ?? (is_array($product) ? $this->string($this->field($product, 'ItemName')) : null) ?? 'Abonnement')
                    .($trial ? ' (essai)' : ''),
                publisher: is_array($publisher) ? $this->string($this->field($publisher, 'Name'), 150) : null,
                quantity: $this->int($this->field($subscription, 'Quantity')),
                // 1 actif, 64 en attente ; 2 suspendu, 8 annulé, 32 expiré.
                status: ($status === 0 || ($status & (1 | 64)) !== 0) && ($status & (2 | 8 | 32)) === 0 ? LicenseStatus::Active : LicenseStatus::Cancelled,
                startsAt: $this->date($this->field($subscription, 'StartDate')),
                expiresAt: $this->date($this->field($subscription, 'EndDate')),
                billingPeriod: match ($this->field($subscription, 'TermDuration')) {
                    'P1M' => BillingPeriod::Monthly,
                    'P1Y' => BillingPeriod::Yearly,
                    'P3Y', 'P2Y' => BillingPeriod::MultiYear,
                    null => null,
                    default => BillingPeriod::Other,
                },
                autoRenewal: $this->field($subscription, 'AutoRenewEnabled') === null ? null : (bool) $this->field($subscription, 'AutoRenewEnabled'),
                reference: (is_array($product) ? $this->string($this->field($product, 'PartNumber')) : null) ?? $this->string($this->field($subscription, 'PublisherSubscriptionId')),
            );
        }
    }

    /**
     * Champ sans tenir compte de la casse (documentation en PascalCase,
     * spécification en camelCase).
     *
     * @param  array<string, mixed>  $item
     */
    private function field(array $item, string $name): mixed
    {
        if (array_key_exists($name, $item)) {
            return $item[$name];
        }

        foreach ($item as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function items(array $json): array
    {
        $items = array_is_list($json) ? $json : ($this->field($json, 'Items') ?? []);

        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function paginate(IntegrationContext $context, string $path, array $filters, string $what): array
    {
        $request = $this->request($context);
        $all = [];

        for ($page = 1; $page <= 100; $page++) {
            $json = $this->getJson($context, $request, self::API.$path, [...$filters, 'page' => $page, 'pageSize' => 100], $what);
            $items = $this->items($json);
            $all = [...$all, ...$items];
            $total = array_is_list($json) ? null : $this->field($json, 'TotalHits');

            if (count($items) < 100 || ($total !== null && count($all) >= (int) $total)) {
                break;
            }
        }

        return $all;
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $context->token('crayon', function () use ($context): array {
            $response = $context->guard('Connexion à Crayon', fn () => $context->http()
                ->withBasicAuth($context->credential('client_id'), $context->credential('client_secret'))
                ->asForm()
                ->post(self::API.'/connect/token', [
                    'grant_type' => 'password',
                    'username' => $context->credential('username'),
                    'password' => $context->credential('password'),
                    'scope' => 'CustomerApi',
                ]));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, 'Connexion à Crayon');
            }

            $json = (array) $response->json();
            $token = $this->field($json, 'AccessToken') ?? $this->field($json, 'access_token');

            return is_string($token) && $token !== ''
                ? [$token, (int) ($this->field($json, 'ExpiresIn') ?? $this->field($json, 'expires_in') ?? 3600)]
                : throw IntegrationException::invalidResponse('Connexion à Crayon');
        });

        return $context->http()->withToken($token);
    }
}
