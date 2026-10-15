<?php

namespace App\Services\Integrations\Providers;

use App\Enums\BillingPeriod;
use App\Enums\IntegrationCategory;
use App\Enums\LicenseStatus;
use App\Models\IntegrationAccount;
use App\Services\Integrations\Data\ExternalAccount;
use App\Services\Integrations\Data\ExternalLicense;
use App\Services\Integrations\IntegrationContext;
use App\Services\Integrations\ProviderDefinition;
use App\Services\Integrations\Support\ServiceUrl;
use Illuminate\Http\Client\PendingRequest;

/**
 * Arrow ArrowSphere (xSP) : clients et licences cloud (éditeur, offre,
 * sièges, échéance, renouvellement), avec une clé d'API.
 */
class ArrowSphereProvider extends AbstractProvider
{
    public const KEY = 'arrowsphere';

    private const DEFAULT_URL = 'https://xsp.arrow.com/index.php/api';

    /** Domaines autorisés pour l'adresse de l'API (pas d'appel vers un autre serveur). */
    private const ALLOWED_HOSTS = ['arrow.com', 'arrowsphere.com'];

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Arrow ArrowSphere',
            vendor: 'Arrow ECS',
            category: IntegrationCategory::Distributor,
            description: 'Licences cloud achetées sur ArrowSphere (Microsoft, sécurité, sauvegarde…) pour chaque client.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::secret('api_key', 'Clé d\'API'),
            ],
            settings: [
                ProviderDefinition::text('base_url', 'Adresse de l\'API', required: false, help: 'À modifier seulement si Arrow vous a communiqué une autre adresse (domaine arrow.com ou arrowsphere.com).', placeholder: self::DEFAULT_URL, default: self::DEFAULT_URL, allowedHosts: self::ALLOWED_HOSTS),
            ],
            setup: [
                'Dans ArrowSphere : menu API › API Keys, créez une clé dédiée à TechIndex.',
                'Collez la clé ici ; l\'adresse de l\'API par défaut convient à la plupart des comptes.',
            ],
            docsUrl: 'https://github.com/ArrowSphere/public-api-client',
            initials: 'AR',
            color: '#000000',
            beta: true,
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $json = $this->getJson($context, $this->request($context), $this->base($context).'/customers', ['per_page' => 1, 'page' => 1], 'Lecture des clients');

        return 'Connexion réussie à ArrowSphere'.(isset($json['data']['customers']) ? '.' : ' (réponse inhabituelle : vérifiez la première synchronisation).');
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        $request = $this->request($context);

        for ($page = 1; $page <= 200; $page++) {
            $json = $this->getJson($context, $request, $this->base($context).'/customers', ['per_page' => 100, 'page' => $page], 'Lecture des clients');

            foreach ($json['data']['customers'] ?? [] as $customer) {
                $id = is_array($customer) ? $this->string($customer['Reference'] ?? null) : null;

                if ($id === null || ! empty($customer['DeletedAt'])) {
                    continue;
                }

                yield new ExternalAccount($id, $this->string($customer['CompanyName'] ?? null) ?? $id, $this->string($customer['Details']['DomainName'] ?? null), [
                    'Ville' => $this->string($customer['City'] ?? null),
                    'Locataire Microsoft' => $this->string($customer['Details']['TenantId'] ?? null),
                ]);
            }

            if ($page >= (int) ($json['pagination']['total_page'] ?? $json['data']['pagination']['total_page'] ?? 1)) {
                break;
            }
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $request = $this->request($context);

        for ($page = 1; $page <= 100; $page++) {
            $json = $this->json($context, 'Lecture des licences', fn () => $request->post(
                $this->base($context).'/licenses/v2/find?'.http_build_query(['per_page' => 100, 'page' => $page]),
                ['filters' => ['license.customer_ref' => $account->external_id]],
            ));

            foreach ($json['results'] ?? [] as $result) {
                $license = is_array($result['license'] ?? null) ? $result['license'] : null;
                $id = $license === null ? null : $this->string($license['partner_ref'] ?? null);

                if ($id === null) {
                    continue;
                }

                $status = strtolower((string) ($license['status_label'] ?? $license['status_code'] ?? ''));

                yield new ExternalLicense(
                    externalId: $id,
                    name: ($this->string($license['offer'] ?? null) ?? $this->string($result['offer']['name'] ?? null) ?? $this->string($license['sku'] ?? null) ?? 'Licence')
                        .(($license['trial'] ?? false) ? ' (essai)' : ''),
                    publisher: $this->string($license['vendor_name'] ?? null, 150),
                    quantity: $this->int($license['seat'] ?? null),
                    // activation_ok, active… : en vigueur ; expired, cancelled, suspended… : terminée.
                    status: $status === '' || preg_match('/(ok|active|pending|progress)/', $status) === 1 ? LicenseStatus::Active : LicenseStatus::Cancelled,
                    startsAt: $this->date($license['start_date'] ?? null),
                    expiresAt: $this->date($license['next_renewal_date'] ?? $license['end_date'] ?? null),
                    billingPeriod: match ((int) ($license['term'] ?? 0)) {
                        720 => BillingPeriod::Monthly,
                        8640, 8760 => BillingPeriod::Yearly,
                        0 => null,
                        default => (int) $license['term'] > 8760 ? BillingPeriod::MultiYear : BillingPeriod::Other,
                    },
                    autoRenewal: isset($license['auto_renew']) ? (bool) $license['auto_renew'] : null,
                    reference: $this->string($license['vendor_subscription_id'] ?? null) ?? $id,
                );
            }

            if ($page >= (int) ($json['pagination']['totalPage'] ?? 1)) {
                break;
            }
        }
    }

    private function base(IntegrationContext $context): string
    {
        return ServiceUrl::https((string) $context->setting('base_url', self::DEFAULT_URL), self::ALLOWED_HOSTS, 'Adresse de l\'API ArrowSphere');
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        return $context->http()->withHeaders(['apiKey' => $context->credential('api_key')]);
    }
}
