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
 * Google Workspace pour les revendeurs (Reseller API) : compte de service
 * avec délégation au niveau du domaine, au nom d'un administrateur du
 * domaine revendeur. Licences par client : édition, sièges achetés et
 * attribués, engagement.
 */
class GoogleWorkspaceProvider extends AbstractProvider
{
    public const KEY = 'google_workspace';

    private const API = 'https://reseller.googleapis.com/apps/reseller/v1';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/apps.order.readonly';

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Google Workspace (revendeur)',
            vendor: 'Google',
            category: IntegrationCategory::Cloud,
            description: 'Abonnements Google Workspace de vos clients revendeur : édition, sièges achetés et attribués, fin d\'engagement.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::secret('service_account', 'Clé JSON du compte de service', help: 'Contenu complet du fichier JSON téléchargé depuis Google Cloud.', multiline: true),
            ],
            settings: [
                ProviderDefinition::text('admin_email', 'Administrateur du domaine revendeur', placeholder: 'admin@revendeur.fr', help: 'Compte au nom duquel le compte de service interroge l\'API.'),
            ],
            setup: [
                'Dans un projet Google Cloud, activez « Google Workspace Reseller API ».',
                'IAM et administration › Comptes de service › Créer, puis Clés › Ajouter une clé › JSON.',
                'Dans la console d\'administration du domaine revendeur (super-administrateur) : Sécurité › Accès et contrôle des données › Commandes des API › Gérer la délégation au niveau du domaine › Ajouter, avec l\'ID client du compte de service et le champ d\'application https://www.googleapis.com/auth/apps.order.readonly.',
                'Collez ici le contenu du fichier JSON et l\'adresse d\'un administrateur du domaine revendeur.',
            ],
            docsUrl: 'https://developers.google.com/workspace/admin/reseller/v1/how-tos/authorize',
            initials: 'GW',
            color: '#1a73e8',
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $json = $this->getJson($context, $this->request($context), self::API.'/subscriptions', ['maxResults' => 1], 'Lecture des abonnements');

        return isset($json['subscriptions']) || array_key_exists('kind', $json)
            ? 'Connexion réussie à l\'API revendeur Google Workspace.'
            : throw IntegrationException::invalidResponse('Lecture des abonnements');
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        // Pas de liste des clients dans l'API : ils sont déduits des abonnements.
        $customers = [];

        foreach ($this->subscriptions($context) as $subscription) {
            $id = $this->string($subscription['customerId'] ?? null);

            if ($id !== null && ! isset($customers[$id])) {
                $customers[$id] = $this->string($subscription['customerDomain'] ?? null);
            }
        }

        $request = $this->request($context);

        foreach ($customers as $id => $domain) {
            $name = $domain ?? $id;

            try {
                $customer = $this->getJson($context, $request, self::API.'/customers/'.rawurlencode($id), [], 'Lecture d\'un client');
                $name = $this->string($customer['postalAddress']['organizationName'] ?? null) ?? $name;
            } catch (IntegrationException) {
                // Nom de l'organisation facultatif : le domaine suffit.
            }

            yield new ExternalAccount($id, $name, $domain);
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        foreach ($this->subscriptions($context, $account->external_id) as $subscription) {
            $skuId = $this->string($subscription['skuId'] ?? null);

            if ($skuId === null) {
                continue;
            }

            $plan = is_array($subscription['plan'] ?? null) ? $subscription['plan'] : [];
            $planName = (string) ($plan['planName'] ?? '');
            $seats = is_array($subscription['seats'] ?? null) ? $subscription['seats'] : [];
            $renewal = (string) ($subscription['renewalSettings']['renewalType'] ?? '');

            yield new ExternalLicense(
                // L'identifiant d'abonnement change à chaque modification : client + produit est stable.
                externalId: $account->external_id.':'.$skuId,
                name: ($this->string($subscription['skuName'] ?? null) ?? $skuId).($planName === 'TRIAL' ? ' (essai)' : ''),
                publisher: 'Google',
                quantity: $this->int($seats['numberOfSeats'] ?? null) ?? $this->int($seats['maximumNumberOfSeats'] ?? null),
                assigned: $this->int($seats['licensedNumberOfSeats'] ?? null),
                status: in_array($subscription['status'] ?? 'ACTIVE', ['ACTIVE', 'PENDING'], true) ? LicenseStatus::Active : LicenseStatus::Cancelled,
                startsAt: $this->date($plan['commitmentInterval']['startTime'] ?? $subscription['creationTime'] ?? null, milliseconds: true),
                expiresAt: $this->date($plan['commitmentInterval']['endTime'] ?? $subscription['trialSettings']['trialEndTime'] ?? null, milliseconds: true),
                billingPeriod: match (true) {
                    str_starts_with($planName, 'ANNUAL') => BillingPeriod::Yearly,
                    $planName === 'FLEXIBLE' => BillingPeriod::Monthly,
                    $planName === '' => null,
                    default => BillingPeriod::Other,
                },
                autoRenewal: $renewal === '' ? null : $renewal !== 'CANCEL',
                reference: $this->string($subscription['subscriptionId'] ?? null),
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subscriptions(IntegrationContext $context, ?string $customerId = null): array
    {
        $request = $this->request($context);
        $items = [];
        $token = null;

        for ($page = 0; $page < 200; $page++) {
            $json = $this->getJson($context, $request, self::API.'/subscriptions', array_filter([
                'maxResults' => 100,
                'pageToken' => $token,
                'customerId' => $customerId,
            ]), 'Lecture des abonnements');

            $items = [...$items, ...array_filter($json['subscriptions'] ?? [], 'is_array')];
            $token = $this->string($json['nextPageToken'] ?? null, 2000);

            if ($token === null) {
                break;
            }
        }

        return $items;
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $context->token('google', function () use ($context): array {
            $response = $context->guard('Connexion à Google', fn () => $context->http()->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($context),
            ]));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, 'Connexion à Google');
            }

            $token = $response->json('access_token');

            return is_string($token) ? [$token, (int) ($response->json('expires_in') ?? 3600)] : throw IntegrationException::invalidResponse('Connexion à Google');
        });

        return $context->http()->withToken($token);
    }

    /**
     * Jeton signé (JWT RS256) du compte de service, au nom de l'administrateur.
     */
    private function assertion(IntegrationContext $context): string
    {
        $account = json_decode($context->credential('service_account'), true);
        $email = $context->setting('admin_email');

        if (! is_array($account) || ! is_string($account['client_email'] ?? null) || ! is_string($account['private_key'] ?? null)) {
            throw new IntegrationException('La clé JSON du compte de service est incomplète (client_email et private_key attendus).');
        }

        if (blank($email)) {
            throw new IntegrationException('Indiquez l\'adresse de l\'administrateur du domaine revendeur.');
        }

        $now = time();
        $segments = [
            $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64url(json_encode([
                'iss' => $account['client_email'],
                'sub' => $email,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR)),
        ];

        $key = openssl_pkey_get_private($account['private_key']);

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new IntegrationException('La clé privée du compte de service est illisible.');
        }

        return implode('.', [...$segments, $this->base64url($signature)]);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
