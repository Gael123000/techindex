<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationCategory;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use App\Models\IntegrationAccount;
use App\Services\Integrations\Data\ExternalAccount;
use App\Services\Integrations\Data\ExternalLicense;
use App\Services\Integrations\IntegrationContext;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\ProviderDefinition;
use Illuminate\Http\Client\PendingRequest;

/**
 * Sophos Central Partner : tenants clients et leurs licences (produit,
 * quantité, utilisation pour les licences MSP, dates).
 */
class SophosProvider extends AbstractProvider
{
    public const KEY = 'sophos';

    private const GLOBAL = 'https://api.central.sophos.com';

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Sophos Central Partner',
            vendor: 'Sophos',
            category: IntegrationCategory::Security,
            description: 'Licences Sophos de chaque tenant client : produit, quantité, utilisation (licences MSP), début et fin.',
            accountLabel: 'tenant',
            credentials: [
                ProviderDefinition::text('client_id', 'Client ID'),
                ProviderDefinition::secret('client_secret', 'Client Secret'),
            ],
            setup: [
                'Dans Sophos Central Partner (Partner Super Admin) : Global Settings › API Credentials Management › Add Credential.',
                'Choisissez le rôle « Service Principal Management » (ou Super Admin) et recopiez le Client ID et le secret, affiché une seule fois.',
            ],
            docsUrl: 'https://developer.sophos.com/license-management',
            initials: 'SO',
            color: '#0067ff',
            beta: true,
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $json = $this->getJson($context, $this->partnerRequest($context), self::GLOBAL.'/partner/v1/tenants', ['pageTotal' => 'true', 'page' => 1, 'pageSize' => 1], 'Lecture des tenants');

        return 'Connexion réussie : '.$this->count((int) ($json['pages']['items'] ?? $json['pages']['total'] ?? count($json['items'] ?? [])), 'tenant').' dans Sophos Central.';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        $request = $this->partnerRequest($context);
        $total = 1;

        for ($page = 1; $page <= min($total, 200); $page++) {
            $json = $this->getJson($context, $request, self::GLOBAL.'/partner/v1/tenants', array_filter([
                'pageTotal' => $page === 1 ? 'true' : null,
                'page' => $page,
                'pageSize' => 100,
            ]), 'Lecture des tenants');

            if ($page === 1) {
                $total = max(1, (int) ($json['pages']['total'] ?? 1));
            }

            foreach ($json['items'] ?? [] as $tenant) {
                $id = is_array($tenant) ? $this->string($tenant['id'] ?? null) : null;

                if ($id !== null) {
                    yield new ExternalAccount($id, $this->string($tenant['name'] ?? $tenant['showAs'] ?? null) ?? $id, null, [
                        'Région' => $this->string($tenant['dataRegion'] ?? null),
                        'Facturation' => $this->string($tenant['billingType'] ?? null),
                        'Statut' => $this->string($tenant['status'] ?? null),
                    ]);
                }
            }
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $json = $this->getJson($context, $this->request($context)->withHeaders(['X-Tenant-ID' => $account->external_id]), self::GLOBAL.'/licenses/v1/licenses', [], 'Lecture des licences');

        foreach ($json['licenses'] ?? [] as $license) {
            $id = is_array($license) ? $this->string($license['id'] ?? $license['licenseIdentifier'] ?? null) : null;

            if ($id === null) {
                continue;
            }

            $end = $this->date($license['endDate'] ?? null);
            $type = (string) ($license['type'] ?? '');

            yield new ExternalLicense(
                externalId: $id,
                name: ($this->string($license['product']['name'] ?? null) ?? $this->string($license['product']['code'] ?? null) ?? 'Licence Sophos')
                    .($type === 'trial' ? ' (essai)' : ''),
                publisher: 'Sophos',
                quantity: ($license['unlimited'] ?? false) ? null : $this->int($license['quantity'] ?? null),
                assigned: $this->int($license['usage']['current']['count'] ?? null),
                status: $end !== null && $end->isPast() ? LicenseStatus::Cancelled : LicenseStatus::Active,
                startsAt: $this->date($license['startDate'] ?? null),
                expiresAt: ($license['perpetual'] ?? false) ? null : $end,
                reference: $this->string($license['licenseIdentifier'] ?? null),
                kind: ($license['perpetual'] ?? false) ? LicenseKind::License : LicenseKind::Subscription,
            );
        }
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $this->oauthToken($context, 'sophos', 'https://id.sophos.com/api/v2/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $context->credential('client_id'),
            'client_secret' => $context->credential('client_secret'),
            'scope' => 'token',
        ], 'Connexion à Sophos Central');

        return $context->http()->withToken($token);
    }

    private function partnerRequest(IntegrationContext $context): PendingRequest
    {
        $request = $this->request($context);
        $whoami = $this->getJson($context, $request, self::GLOBAL.'/whoami/v1', [], 'Identification du compte');

        if (($whoami['idType'] ?? null) !== 'partner' || ! is_string($whoami['id'] ?? null)) {
            throw new IntegrationException('Ces identifiants ne sont pas ceux d\'un compte partenaire Sophos Central (créez-les dans Sophos Central Partner).');
        }

        return $request->withHeaders(['X-Partner-ID' => $whoami['id']]);
    }
}
