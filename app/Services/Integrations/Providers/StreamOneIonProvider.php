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
 * TD SYNNEX StreamOne Ion (API v3) : clients et abonnements cloud.
 *
 * Le jeton de rafraîchissement n'est valable qu'une fois (32 jours) : chaque
 * connexion en fournit un nouveau, enregistré aussitôt, chiffré. Les
 * synchronisations d'une même intégration ne s'exécutent jamais en parallèle.
 */
class StreamOneIonProvider extends AbstractProvider
{
    public const KEY = 'streamone_ion';

    private const HOST = 'https://ion.tdsynnex.com';

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'TD SYNNEX StreamOne Ion',
            vendor: 'TD SYNNEX',
            category: IntegrationCategory::Distributor,
            description: 'Clients et abonnements cloud achetés sur StreamOne Ion : produit, éditeur, licences, échéance, renouvellement.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::secret('refresh_token', 'Jeton de rafraîchissement', help: 'Valable 32 jours et une seule fois : TechIndex le renouvelle à chaque synchronisation. Synchronisez au moins une fois par mois.'),
            ],
            settings: [
                ProviderDefinition::text('account_id', 'Numéro de compte (Account ID)', placeholder: '1234'),
            ],
            setup: [
                'Dans StreamOne Ion : Settings › Users, créez un utilisateur dédié à TechIndex (rôle autre que « User »).',
                'Sur cet utilisateur : Edit › « Request New Credentials », puis copiez le jeton de rafraîchissement.',
                'Le numéro de compte figure dans Settings › Account Information.',
                'Laissez la synchronisation automatique activée : le jeton expire après 32 jours sans connexion.',
            ],
            docsUrl: 'https://docs.streamone.cloud/',
            initials: 'TD',
            color: '#005758',
            beta: true,
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $json = $this->getJson($context, $this->request($context), $this->base($context).'/customers/', ['pageSize' => 1], 'Lecture des clients');

        return 'Connexion réussie à StreamOne Ion'.(isset($json['customers']) ? '.' : ' (réponse inhabituelle : vérifiez la première synchronisation).');
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        $request = $this->request($context);
        $token = null;

        for ($page = 0; $page < 200; $page++) {
            $json = $this->getJson($context, $request, $this->base($context).'/customers/', array_filter(['pageSize' => 100, 'pageToken' => $token]), 'Lecture des clients');

            foreach ($json['customers'] ?? [] as $customer) {
                // « accounts/1234/customers/56789 » : l'identifiant est le dernier segment.
                $id = $this->string(basename((string) ($customer['name'] ?? '')));

                if ($id === null || $id === '' || ($customer['customerStatus'] ?? null) === 'DELETED') {
                    continue;
                }

                yield new ExternalAccount($id, $this->string($customer['customerOrganization'] ?? null) ?? $id, $this->domainOf($customer['customerEmail'] ?? null), [
                    'Ville' => $this->string($customer['customerAddress']['city'] ?? null),
                    'Statut' => $this->string($customer['customerStatus'] ?? null),
                ]);
            }

            $token = $this->string($json['nextPageToken'] ?? null, 2000);

            if ($token === null) {
                break;
            }
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $request = $this->request($context);

        for ($offset = 0, $page = 0; $page < 100; $page++, $offset += 100) {
            $json = $this->getJson($context, $request, $this->base($context).'/subscriptions', [
                'customer_id' => $account->external_id,
                'pagination.limit' => 100,
                'pagination.offset' => $offset,
            ], 'Lecture des abonnements');

            // Enveloppe de la liste selon les versions de l'API.
            $items = $json['subscriptions'] ?? $json['items'] ?? (array_is_list($json) ? $json : []);

            foreach ($items as $subscription) {
                if (! is_array($subscription) || (string) ($subscription['customerId'] ?? $account->external_id) !== $account->external_id) {
                    continue;
                }

                $id = $this->string($subscription['subscriptionId'] ?? null);

                if ($id === null) {
                    continue;
                }

                $product = is_array($subscription['ccpProductInfo'] ?? null) ? $subscription['ccpProductInfo'] : [];
                $status = strtoupper((string) ($subscription['subscriptionStatus'] ?? 'ACTIVE'));

                yield new ExternalLicense(
                    externalId: $id,
                    name: $this->string($product['productDisplayName'] ?? null) ?? $this->string($subscription['subscriptionName'] ?? null) ?? 'Abonnement',
                    publisher: $this->string($subscription['cloudProviderName'] ?? null, 150),
                    quantity: $this->int($subscription['subscriptionTotalLicenses'] ?? null),
                    status: in_array($status, ['ACTIVE', 'PENDING', 'PROVISIONING'], true) ? LicenseStatus::Active : LicenseStatus::Cancelled,
                    startsAt: $this->date($subscription['subscriptionStartDate'] ?? null),
                    expiresAt: $this->date($subscription['subscriptionEndDate'] ?? null),
                    billingPeriod: $this->period((string) ($subscription['subscriptionBillingTerm'] ?? $subscription['subscriptionBillingCycle'] ?? '')),
                    autoRenewal: isset($subscription['autoRenew']) ? filter_var($subscription['autoRenew'], FILTER_VALIDATE_BOOL) : null,
                    reference: $this->string($product['skuDisplayName'] ?? null) ?? $id,
                );
            }

            if (count($items) < 100) {
                break;
            }
        }
    }

    private function period(string $term): ?BillingPeriod
    {
        $term = strtolower($term);

        return match (true) {
            $term === '' => null,
            str_contains($term, 'month') => BillingPeriod::Monthly,
            str_contains($term, 'annual') || str_contains($term, 'year') && ! preg_match('/[23]/', $term) => BillingPeriod::Yearly,
            str_contains($term, 'year') => BillingPeriod::MultiYear,
            default => BillingPeriod::Other,
        };
    }

    private function domainOf(mixed $email): ?string
    {
        return is_string($email) && str_contains($email, '@') ? $this->string(substr(strrchr($email, '@') ?: '', 1)) : null;
    }

    private function base(IntegrationContext $context): string
    {
        $account = (string) $context->setting('account_id');

        if (preg_match('/^\d{1,20}$/', $account) !== 1) {
            throw new IntegrationException('Indiquez le numéro de compte StreamOne Ion (chiffres uniquement).');
        }

        return self::HOST.'/api/v3/accounts/'.$account;
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $context->token('streamone', function () use ($context): array {
            $response = $context->guard('Connexion à StreamOne Ion', fn () => $context->http()->asForm()->post(self::HOST.'/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $context->credential('refresh_token'),
            ]));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, 'Connexion à StreamOne Ion (jeton expiré ? générez-en un nouveau)');
            }

            $access = $response->json('access_token');
            $refresh = $response->json('refresh_token');

            if (! is_string($access) || $access === '') {
                throw IntegrationException::invalidResponse('Connexion à StreamOne Ion');
            }

            // Jeton de rafraîchissement à usage unique : le nouveau remplace l'ancien immédiatement.
            if (is_string($refresh) && $refresh !== '') {
                $context->replaceCredential('refresh_token', $refresh);
            }

            return [$access, (int) ($response->json('expires_in') ?? 7200)];
        });

        return $context->http()->withToken($token);
    }
}
