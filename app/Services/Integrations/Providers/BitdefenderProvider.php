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

/**
 * Bitdefender GravityZone Cloud (API JSON-RPC) : sociétés clientes et leur
 * licence (postes achetés et utilisés, échéance, type d'abonnement).
 */
class BitdefenderProvider extends AbstractProvider
{
    public const KEY = 'bitdefender';

    private const DEFAULT_URL = 'https://cloud.gravityzone.bitdefender.com/api';

    private const ALLOWED_HOSTS = ['bitdefender.com', 'bitdefender.net'];

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Bitdefender GravityZone',
            vendor: 'Bitdefender',
            category: IntegrationCategory::Security,
            description: 'Licences GravityZone de chaque société cliente : postes achetés et utilisés, échéance, type d\'abonnement.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::secret('api_key', 'Clé d\'API'),
            ],
            settings: [
                ProviderDefinition::text('base_url', 'URL d\'accès à l\'API', required: false, placeholder: self::DEFAULT_URL, default: self::DEFAULT_URL, help: 'Affichée dans Mon compte › API du Control Center.', allowedHosts: self::ALLOWED_HOSTS),
            ],
            setup: [
                'Dans GravityZone : Mon compte › Clés d\'API › Ajouter, en cochant les API « Companies », « Licensing » et « Network ».',
                'Recopiez la clé et, si elle diffère, l\'URL d\'accès affichée sur la même page.',
            ],
            docsUrl: 'https://www.bitdefender.com/business/support/en/77211-125277-public-api.html',
            initials: 'BD',
            color: '#ed1c24',
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $companies = $this->rpc($context, 'network', 'getCompaniesList', ['filters' => ['companyType' => 1]], 'Lecture des sociétés');

        return 'Connexion réussie : '.$this->count(is_array($companies) ? count($companies) : 0, 'société cliente', 'sociétés clientes').' au premier niveau.';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        // Clients directs, puis ceux des partenaires (revendeurs) jusqu'à trois niveaux.
        $parents = [null];

        for ($depth = 0; $depth < 3 && $parents !== []; $depth++) {
            $next = [];

            foreach ($parents as $parentId) {
                $params = $parentId === null ? [] : ['parentId' => $parentId];

                foreach ((array) $this->rpc($context, 'network', 'getCompaniesList', [...$params, 'filters' => ['companyType' => 1]], 'Lecture des sociétés') as $company) {
                    $id = is_array($company) ? $this->string($company['id'] ?? null) : null;

                    if ($id !== null) {
                        yield new ExternalAccount($id, $this->string($company['name'] ?? null) ?? $id);
                    }
                }

                foreach ((array) $this->rpc($context, 'network', 'getCompaniesList', [...$params, 'filters' => ['companyType' => 0]], 'Lecture des partenaires') as $partner) {
                    $id = is_array($partner) ? $this->string($partner['id'] ?? null) : null;

                    if ($id !== null) {
                        $next[] = $id;
                    }
                }
            }

            $parents = $next;
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $info = $this->rpc($context, 'licensing', 'getLicenseInfo', ['companyId' => $account->external_id, 'returnAllProducts' => true], 'Lecture de la licence');

        if (! is_array($info)) {
            return;
        }

        $licenses = [['main' => true, ...$info], ...array_filter($info['additionalLicenses'] ?? [], 'is_array')];

        foreach ($licenses as $index => $license) {
            $key = $this->string($license['licenseKey'] ?? null);
            $type = (int) ($license['subscriptionType'] ?? 0);
            $expires = $this->date($license['expiryDate'] ?? $license['endSubscription'] ?? null);

            if ($key === null && ! isset($license['totalSlots'])) {
                continue;
            }

            yield new ExternalLicense(
                externalId: $key ?? 'licence-'.$index,
                name: 'Bitdefender GravityZone'.(($license['main'] ?? false) ? '' : ' – licence complémentaire').(in_array($type, [1, 4, 5], true) ? ' (essai)' : ''),
                publisher: 'Bitdefender',
                quantity: $this->int($license['totalSlots'] ?? null),
                assigned: $this->int($license['usedSlots'] ?? null),
                status: $expires !== null && $expires->isPast() ? LicenseStatus::Cancelled : LicenseStatus::Active,
                expiresAt: $expires,
                billingPeriod: match ($type) {
                    2 => BillingPeriod::Yearly,
                    3, 4, 5 => BillingPeriod::Monthly,
                    0 => null,
                    default => BillingPeriod::Other,
                },
                autoRenewal: isset($license['autoRenewPeriod']) ? (bool) $license['autoRenewPeriod'] : null,
                reference: $key,
            );
        }
    }

    /**
     * Appel JSON-RPC 2.0 ; renvoie le champ « result ».
     *
     * @param  array<string, mixed>  $params
     */
    private function rpc(IntegrationContext $context, string $module, string $method, array $params, string $what): mixed
    {
        $base = ServiceUrl::https((string) $context->setting('base_url', self::DEFAULT_URL), self::ALLOWED_HOSTS, 'URL d\'accès Bitdefender');

        $json = $this->json($context, $what, fn () => $context->http()
            ->withBasicAuth($context->credential('api_key'), '')
            ->post($base.'/v1.0/jsonrpc/'.$module, ['id' => (string) random_int(1, 999999), 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params]));

        if (isset($json['error'])) {
            $code = (int) ($json['error']['code'] ?? 0);

            throw new IntegrationException($what.' : '.match ($code) {
                -32001 => 'clé d\'API refusée ou API non autorisée pour cette clé',
                -32003 => 'trop de requêtes, nouvel essai à la prochaine synchronisation',
                default => 'erreur du service',
            }." (code {$code}).");
        }

        return $json['result'] ?? null;
    }
}
