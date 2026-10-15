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
 * Microsoft Partner Center (CSP) : clients du partenaire et abonnements
 * achetés (offre, quantité, engagement, renouvellement automatique), en
 * authentification d'application seule. Les licences attribuées ne sont
 * disponibles qu'avec le connecteur Microsoft 365.
 */
class PartnerCenterProvider extends AbstractProvider
{
    public const KEY = 'partner_center';

    private const API = 'https://api.partnercenter.microsoft.com/v1';

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Microsoft Partner Center (CSP)',
            vendor: 'Microsoft',
            category: IntegrationCategory::Cloud,
            description: 'Abonnements Microsoft achetés en direct (CSP) pour chaque client : offre, quantité, fin d\'engagement, renouvellement.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::secret('client_secret', 'Clé de l\'application', help: 'Clé générée dans Partner Center pour l\'application web.'),
            ],
            settings: [
                ProviderDefinition::text('tenant', 'Locataire partenaire', placeholder: 'votre-societe.onmicrosoft.com', help: 'Identifiant ou domaine de votre locataire Partner Center.'),
                ProviderDefinition::text('client_id', 'ID d\'application', placeholder: '00000000-0000-0000-0000-000000000000'),
            ],
            setup: [
                'Connectez-vous à Partner Center avec un compte administrateur général.',
                'Paramètres du compte › Gestion des applications › Ajouter une application web (ou « Ajouter une clé » pour une application existante).',
                'Recopiez l\'ID d\'application, la clé et l\'identifiant de votre locataire.',
                'Pour le nombre de licences attribuées par client, activez aussi le connecteur Microsoft 365.',
            ],
            docsUrl: 'https://learn.microsoft.com/fr-fr/partner-center/developer/partner-center-authentication',
            initials: 'CSP',
            color: '#0078d4',
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $json = $this->getJson($context, $this->request($context), self::API.'/customers', ['size' => 1], 'Lecture des clients');

        return 'Connexion réussie : '.$this->count((int) ($json['totalCount'] ?? count($json['items'] ?? [])), 'client').' dans Partner Center.';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        $request = $this->request($context);
        $url = self::API.'/customers';
        $query = ['size' => 500];
        $headers = [];

        for ($page = 0; $page < 40 && $url !== null; $page++) {
            $json = $this->getJson($context, $request->withHeaders($headers), $url, $query, 'Lecture des clients');

            foreach ($json['items'] ?? [] as $customer) {
                $profile = is_array($customer['companyProfile'] ?? null) ? $customer['companyProfile'] : [];
                $id = $this->string($profile['tenantId'] ?? $customer['id'] ?? null);

                if ($id !== null) {
                    yield new ExternalAccount($id, $this->string($profile['companyName'] ?? null) ?? $id, $this->string($profile['domain'] ?? null), [
                        'Relation' => $this->string($customer['relationshipToPartner'] ?? null),
                    ]);
                }
            }

            // Page suivante : lien relatif et jeton de continuation fournis par le service.
            $next = $json['links']['next'] ?? null;
            $uri = is_array($next) ? $this->string($next['uri'] ?? null, 2000) : null;

            if ($uri === null) {
                break;
            }

            $url = self::API.'/'.ltrim($uri, '/');
            $query = [];
            $headers = collect($next['headers'] ?? [])
                ->filter(fn ($header): bool => is_array($header) && is_string($header['key'] ?? null) && is_string($header['value'] ?? null))
                ->mapWithKeys(fn (array $header): array => [$header['key'] => $header['value']])
                ->only(['MS-ContinuationToken'])
                ->all();
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $json = $this->getJson($context, $this->request($context), self::API.'/customers/'.rawurlencode($account->external_id).'/subscriptions', [], 'Lecture des abonnements');

        foreach ($json['items'] ?? [] as $subscription) {
            $id = $this->string($subscription['id'] ?? null);

            if ($id === null || ($subscription['status'] ?? null) === 'deleted') {
                continue;
            }

            yield new ExternalLicense(
                externalId: $id,
                name: ($this->string($subscription['friendlyName'] ?? null) ?? $this->string($subscription['offerName'] ?? null) ?? 'Abonnement Microsoft')
                    .(($subscription['isTrial'] ?? false) ? ' (essai)' : ''),
                publisher: 'Microsoft',
                quantity: $this->int($subscription['quantity'] ?? null),
                status: in_array($subscription['status'] ?? 'active', ['active', 'pending'], true) ? LicenseStatus::Active : LicenseStatus::Cancelled,
                startsAt: $this->date($subscription['effectiveStartDate'] ?? $subscription['creationDate'] ?? null),
                expiresAt: $this->date($subscription['commitmentEndDate'] ?? null),
                billingPeriod: match ($subscription['termDuration'] ?? null) {
                    'P1M' => BillingPeriod::Monthly,
                    'P1Y' => BillingPeriod::Yearly,
                    'P3Y' => BillingPeriod::MultiYear,
                    default => match ($subscription['billingCycle'] ?? null) {
                        'monthly' => BillingPeriod::Monthly,
                        'annual' => BillingPeriod::Yearly,
                        default => null,
                    },
                },
                autoRenewal: isset($subscription['autoRenewEnabled']) ? (bool) $subscription['autoRenewEnabled'] : null,
                reference: $this->string($subscription['offerId'] ?? null),
            );
        }
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $tenant = (string) $context->setting('tenant');

        if ($tenant === '') {
            throw new IntegrationException('Indiquez votre locataire partenaire dans la configuration.');
        }

        $token = $this->oauthToken($context, 'partner-center', 'https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/token', [
            'grant_type' => 'client_credentials',
            'resource' => 'https://api.partnercenter.microsoft.com',
            'client_id' => (string) $context->setting('client_id'),
            'client_secret' => $context->credential('client_secret'),
        ], 'Connexion à Partner Center');

        return $context->http()->withToken($token);
    }
}
