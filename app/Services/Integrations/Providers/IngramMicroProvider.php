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
 * Ingram Micro Cloud Marketplace (API « SimpleAPI » de la place de marché) :
 * clients et abonnements, une licence par produit de l'abonnement.
 *
 * Le détail d'un abonnement contient aussi des paramètres de provisionnement
 * (dont parfois le mot de passe administrateur du locataire) : seuls les
 * produits et quantités sont lus, rien d'autre n'est conservé.
 */
class IngramMicroProvider extends AbstractProvider
{
    public const KEY = 'ingram_micro';

    private const ALLOWED_HOSTS = ['cloud.im', 'ingrammicro.com'];

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Ingram Micro Cloud Marketplace',
            vendor: 'Ingram Micro',
            category: IntegrationCategory::Distributor,
            description: 'Clients et abonnements de la place de marché cloud Ingram Micro (Xvantage) : produits, quantités, renouvellement.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::text('username', 'Nom d\'utilisateur de l\'API'),
                ProviderDefinition::secret('password', 'Mot de passe de l\'API'),
                ProviderDefinition::secret('subscription_key', 'Clé d\'abonnement (X-Subscription-Key)'),
            ],
            settings: [
                ProviderDefinition::text('base_url', 'Adresse de l\'API', placeholder: 'https://api.cloud.im/marketplace/eu', help: 'Adresse affichée dans le menu « SimpleAPI » de votre panneau revendeur.', allowedHosts: self::ALLOWED_HOSTS),
                ProviderDefinition::text('marketplace', 'Code de la place de marché', placeholder: 'fr', help: 'Code visible dans l\'adresse de votre panneau revendeur (cp.fr.…cloud.im → fr).'),
            ],
            setup: [
                'Dans le panneau revendeur Ingram Micro : Marketplace › « Marketplace API », souscrivez au plan.',
                'Le nouveau menu « SimpleAPI » affiche l\'adresse de l\'API, la clé d\'abonnement et l\'utilisateur de l\'API.',
                'Recopiez ces informations ici, avec le code de votre place de marché.',
            ],
            docsUrl: 'https://developer.ingrammicro.com/reseller/getting-started/api-overview',
            initials: 'IM',
            color: '#0067b1',
            beta: true,
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $json = $this->getJson($context, $this->request($context), $this->base($context).'/customers', ['limit' => 1], 'Lecture des clients');

        return 'Connexion réussie : '.$this->count((int) ($json['pagination']['total'] ?? 0), 'client').' sur la place de marché.';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        foreach ($this->paginate($context, '/customers', [], 'Lecture des clients') as $customer) {
            $id = $this->string($customer['id'] ?? null);

            if ($id !== null && ! in_array($customer['status'] ?? 'active', ['removed', 'deleted'], true)) {
                yield new ExternalAccount($id, $this->string($customer['name'] ?? null) ?? $id, null, [
                    'Statut' => $this->string($customer['status'] ?? null),
                ]);
            }
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $request = $this->request($context);
        $catalog = null;

        foreach ($this->paginate($context, '/subscriptions', ['customerId' => $account->external_id], 'Lecture des abonnements') as $subscription) {
            $id = $this->string($subscription['id'] ?? null);

            if ($id === null || ($subscription['status'] ?? null) === 'removed') {
                continue;
            }

            $detail = $this->getJson($context, $request, $this->base($context).'/subscriptions/'.rawurlencode($id), [], 'Lecture d\'un abonnement');
            // Uniquement les produits : les paramètres de provisionnement sont ignorés.
            $products = array_filter($detail['products'] ?? [], 'is_array');
            $catalog ??= $this->catalog($context);
            $active = in_array($subscription['status'] ?? 'active', ['active', 'pending'], true);

            foreach ($products as $product) {
                $mpn = $this->string($product['mpn'] ?? null);

                if ($mpn === null) {
                    continue;
                }

                $known = $catalog[$mpn] ?? [];

                yield new ExternalLicense(
                    externalId: $id.':'.$mpn,
                    name: $known['name'] ?? 'Produit '.$mpn,
                    publisher: $known['vendor'] ?? null,
                    quantity: is_numeric($product['quantity'] ?? null) ? (int) round((float) $product['quantity']) : null,
                    status: $active ? LicenseStatus::Active : LicenseStatus::Cancelled,
                    startsAt: $this->date($subscription['creationDate'] ?? null),
                    expiresAt: $this->date($subscription['renewalDate'] ?? null),
                    billingPeriod: $known['period'] ?? null,
                    autoRenewal: isset($subscription['renewalStatus']) ? (bool) $subscription['renewalStatus'] : null,
                    reference: $mpn,
                );
            }
        }
    }

    /**
     * Catalogue des produits délégués au revendeur : nom, éditeur, période.
     *
     * @return array<string, array{name: ?string, vendor: ?string, period: ?BillingPeriod}>
     */
    private function catalog(IntegrationContext $context): array
    {
        $catalog = [];

        try {
            foreach ($this->paginate($context, '/products', [], 'Lecture du catalogue', 50) as $product) {
                $mpn = $this->string($product['mpn'] ?? null);

                if ($mpn !== null) {
                    $catalog[$mpn] = [
                        'name' => $this->string($product['name'] ?? null),
                        'vendor' => $this->string($product['vendor'] ?? $product['serviceName'] ?? null, 150),
                        'period' => match ($product['subscriptionPeriod']['type'] ?? $product['billingPeriod']['type'] ?? null) {
                            'month' => BillingPeriod::Monthly,
                            'year' => ((int) ($product['subscriptionPeriod']['duration'] ?? 1)) > 1 ? BillingPeriod::MultiYear : BillingPeriod::Yearly,
                            default => null,
                        },
                    ];
                }
            }
        } catch (IntegrationException) {
            // Catalogue facultatif : les produits restent identifiés par leur référence.
        }

        return $catalog;
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function paginate(IntegrationContext $context, string $path, array $filters, string $what, int $maxPages = 200): array
    {
        $request = $this->request($context);
        $items = [];

        for ($page = 0, $offset = 0; $page < $maxPages; $page++, $offset += 100) {
            $json = $this->getJson($context, $request, $this->base($context).$path, [...$filters, 'offset' => $offset, 'limit' => 100], $what);
            $data = array_filter($json['data'] ?? [], 'is_array');
            $items = [...$items, ...$data];

            if ($data === [] || $offset + 100 >= (int) ($json['pagination']['total'] ?? 0)) {
                break;
            }
        }

        return $items;
    }

    private function base(IntegrationContext $context): string
    {
        return ServiceUrl::https((string) $context->setting('base_url'), self::ALLOWED_HOSTS, 'Adresse de l\'API Ingram Micro');
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $context->token('ingram', function () use ($context): array {
            $response = $context->guard('Connexion à Ingram Micro', fn () => $context->http()
                ->withBasicAuth($context->credential('username'), $context->credential('password'))
                ->withHeaders(['X-Subscription-Key' => $context->credential('subscription_key')])
                ->post($this->base($context).'/token', ['marketplace' => (string) $context->setting('marketplace')]));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, 'Connexion à Ingram Micro');
            }

            $token = $response->json('token') ?? $response->json('access_token');

            return is_string($token) && $token !== '' ? [$token, 1500] : throw IntegrationException::invalidResponse('Connexion à Ingram Micro');
        });

        return $context->http()->withToken($token)->withHeaders(['X-Subscription-Key' => $context->credential('subscription_key')]);
    }
}
