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
 * Pax8 : sociétés clientes et abonnements (produit, éditeur, quantité,
 * engagement), via l'API partenaire (identifiants « client credentials »).
 */
class Pax8Provider extends AbstractProvider
{
    public const KEY = 'pax8';

    private const API = 'https://api.pax8.com/v1';

    /** Statuts d'un abonnement encore en vigueur. */
    private const ACTIVE = ['Active', 'PendingManual', 'PendingAutomated', 'WaitingForDetails', 'Trial', 'PendingActivation', 'Activated', 'PendingCancel'];

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Pax8',
            vendor: 'Pax8',
            category: IntegrationCategory::Distributor,
            description: 'Abonnements achetés sur la place de marché Pax8 (Microsoft, sécurité, sauvegarde…) pour chaque société cliente.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::text('client_id', 'Client ID'),
                ProviderDefinition::secret('client_secret', 'Client Secret'),
            ],
            setup: [
                'Dans l\'application Pax8 : Integrations › API credentials › « + Create API credential ».',
                'Recopiez le Client ID et le Client Secret (affiché une seule fois).',
            ],
            docsUrl: 'https://devx.pax8.com/docs/authentication',
            initials: 'P8',
            color: '#0d4dbd',
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $json = $this->getJson($context, $this->request($context), self::API.'/companies', ['page' => 0, 'size' => 1], 'Lecture des sociétés');

        return 'Connexion réussie : '.$this->count((int) ($json['page']['totalElements'] ?? 0), 'société cliente', 'sociétés clientes').' dans Pax8.';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        foreach ($this->paginate($context, '/companies', [], 'Lecture des sociétés') as $company) {
            $id = $this->string($company['id'] ?? null);

            if ($id === null || ($company['status'] ?? null) === 'Deleted') {
                continue;
            }

            yield new ExternalAccount($id, $this->string($company['name'] ?? null) ?? $id, $this->string($company['website'] ?? null), [
                'Ville' => $this->string($company['address']['city'] ?? null),
                'Statut' => $this->string($company['status'] ?? null),
            ]);
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $products = [];

        foreach ($this->paginate($context, '/subscriptions', ['companyId' => $account->external_id], 'Lecture des abonnements') as $subscription) {
            $id = $this->string($subscription['id'] ?? null);

            if ($id === null || ($subscription['status'] ?? null) === 'Converted') {
                continue;
            }

            $productId = $this->string($subscription['productId'] ?? null);
            $product = $productId === null ? [] : ($products[$productId] ??= $this->product($context, $productId));
            $status = (string) ($subscription['status'] ?? 'Active');

            yield new ExternalLicense(
                externalId: $id,
                name: ($this->string($subscription['productName'] ?? null) ?? $this->string($product['name'] ?? null) ?? 'Abonnement Pax8')
                    .($status === 'Trial' ? ' (essai)' : ''),
                publisher: $this->string($product['vendorName'] ?? null, 150),
                quantity: $this->int($subscription['quantity'] ?? null),
                status: in_array($status, self::ACTIVE, true) ? LicenseStatus::Active : LicenseStatus::Cancelled,
                startsAt: $this->date($subscription['startDate'] ?? $subscription['billingStart'] ?? null),
                expiresAt: $this->date($subscription['commitmentTerm']['endDate'] ?? $subscription['endDate'] ?? null),
                billingPeriod: match ($subscription['billingTerm'] ?? null) {
                    'Monthly' => BillingPeriod::Monthly,
                    'Annual' => BillingPeriod::Yearly,
                    '2-Year', '3-Year' => BillingPeriod::MultiYear,
                    null => null,
                    default => BillingPeriod::Other,
                },
                reference: $this->string($subscription['vendorSubscriptionId'] ?? null) ?? $id,
            );
        }
    }

    /**
     * Produit (nom, éditeur) ; facultatif si la lecture échoue.
     *
     * @return array<string, mixed>
     */
    private function product(IntegrationContext $context, string $productId): array
    {
        try {
            return $this->getJson($context, $this->request($context), self::API.'/products/'.rawurlencode($productId), [], 'Lecture d\'un produit');
        } catch (IntegrationException) {
            return [];
        }
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function paginate(IntegrationContext $context, string $path, array $filters, string $what): array
    {
        $request = $this->request($context);
        $items = [];

        for ($page = 0; $page < 200; $page++) {
            $json = $this->getJson($context, $request, self::API.$path, [...$filters, 'page' => $page, 'size' => 200], $what);
            $items = [...$items, ...array_filter($json['content'] ?? [], 'is_array')];

            if ($page + 1 >= (int) ($json['page']['totalPages'] ?? 1)) {
                break;
            }
        }

        return $items;
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $this->oauthToken($context, 'pax8', self::API.'/token', [
            'client_id' => $context->credential('client_id'),
            'client_secret' => $context->credential('client_secret'),
            'audience' => 'https://api.pax8.com',
            'grant_type' => 'client_credentials',
        ], 'Connexion à Pax8', asJson: true);

        return $context->http()->withToken($token);
    }
}
