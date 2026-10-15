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
 * Acronis Cyber Protect Cloud : clients (tenants) et services activés, avec
 * le quota et l'utilisation (postes, serveurs, machines virtuelles…). Le
 * modèle est à l'usage : pas de date d'échéance.
 */
class AcronisProvider extends AbstractProvider
{
    public const KEY = 'acronis';

    private const ALLOWED_HOSTS = ['acronis.com', 'acronis.eu'];

    /** Libellés des principaux compteurs (les autres gardent leur nom technique). */
    private const USAGE_LABELS = [
        'workstations' => 'Postes de travail',
        'servers' => 'Serveurs',
        'vms' => 'Machines virtuelles',
        'mobiles' => 'Appareils mobiles',
        'web_hosting_servers' => 'Serveurs d\'hébergement web',
        'mailboxes' => 'Boîtes aux lettres',
        'o365_mailboxes' => 'Boîtes aux lettres Microsoft 365',
        'o365_onedrive' => 'OneDrive Microsoft 365',
        'o365_sharepoint_sites' => 'Sites SharePoint',
        'o365_teams' => 'Équipes Microsoft Teams',
        'gsuite_seats' => 'Utilisateurs Google Workspace',
        'nas' => 'NAS',
    ];

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'Acronis Cyber Protect Cloud',
            vendor: 'Acronis',
            category: IntegrationCategory::Backup,
            description: 'Services Acronis activés pour chaque client : quotas et utilisation (postes, serveurs, machines virtuelles, Microsoft 365…).',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::text('client_id', 'Client ID'),
                ProviderDefinition::secret('client_secret', 'Secret'),
            ],
            settings: [
                ProviderDefinition::text('base_url', 'Adresse du centre de données', placeholder: 'https://eu2-cloud.acronis.com', help: 'Adresse affichée lors de la création du client d\'API.', allowedHosts: self::ALLOWED_HOSTS),
            ],
            setup: [
                'Dans le portail de gestion Acronis : Paramètres › Clients API › Créer un client API.',
                'Recopiez le Client ID, le secret (affiché une seule fois) et l\'adresse du centre de données.',
            ],
            docsUrl: 'https://developer.acronis.com/doc/account-management/v2/guide/getting-started/authenticating',
            initials: 'AC',
            color: '#1f6fb5',
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $tenant = $this->ownTenant($context);

        return 'Connexion réussie au compte partenaire (tenant '.$tenant.').';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        $request = $this->request($context);
        $root = $this->ownTenant($context);
        $after = null;

        for ($page = 0; $page < 50; $page++) {
            $json = $this->getJson($context, $request, $this->api($context).'/tenants', array_filter([
                'subtree_root_id' => $root,
                'lod' => 'basic',
                'limit' => 1000,
                'after' => $after,
            ]), 'Lecture des clients');

            foreach ($json['items'] ?? [] as $tenant) {
                if (! is_array($tenant) || ($tenant['kind'] ?? null) !== 'customer' || ! is_string($tenant['id'] ?? null)) {
                    continue;
                }

                yield new ExternalAccount($tenant['id'], $this->string($tenant['name'] ?? null) ?? $tenant['id'], null, [
                    'Actif' => ($tenant['enabled'] ?? true) ? 'oui' : 'non',
                ]);
            }

            $after = $this->string($json['paging']['cursors']['after'] ?? null, 500);

            if ($after === null) {
                break;
            }
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {
        $request = $this->request($context);
        $json = $this->getJson($context, $request, $this->api($context).'/tenants/usages', ['tenants' => $account->external_id], 'Lecture de l\'utilisation');

        $usages = collect($json['items'] ?? [])->firstWhere('tenant', $account->external_id)['usages'] ?? [];

        foreach ($usages as $usage) {
            // Compteurs d'appareils ou d'utilisateurs ; le stockage et les options sont ignorés.
            if (! is_array($usage) || ($usage['type'] ?? null) !== 'count' || ($usage['offering_item']['status'] ?? 1) !== 1) {
                continue;
            }

            $name = (string) ($usage['name'] ?? '');
            $usageName = (string) ($usage['usage_name'] ?? $name);
            $quota = $this->int($usage['offering_item']['quota']['value'] ?? null);
            $value = $this->int($usage['absolute_value'] ?? $usage['value'] ?? null) ?? 0;

            if ($name === '' || ($value === 0 && $quota === null)) {
                continue;
            }

            $edition = $this->string($usage['edition'] ?? null);

            yield new ExternalLicense(
                externalId: ($edition ?? 'default').':'.$name,
                name: 'Acronis – '.(self::USAGE_LABELS[$usageName] ?? $usageName).($edition !== null && $edition !== 'pck_per_workload' ? ' ('.$edition.')' : ''),
                publisher: 'Acronis',
                quantity: $quota,
                assigned: $value,
                status: LicenseStatus::Active,
                billingPeriod: BillingPeriod::Monthly,
                reference: $name,
            );
        }
    }

    private function ownTenant(IntegrationContext $context): string
    {
        return $context->token('acronis-tenant', function () use ($context): array {
            $json = $this->getJson($context, $this->request($context), $this->api($context).'/clients/'.rawurlencode($context->credential('client_id')), [], 'Lecture du client d\'API');
            $tenant = $this->string($json['tenant_id'] ?? null);

            return $tenant !== null ? [$tenant, 86400] : throw IntegrationException::invalidResponse('Lecture du client d\'API');
        });
    }

    private function api(IntegrationContext $context): string
    {
        return ServiceUrl::https((string) $context->setting('base_url'), self::ALLOWED_HOSTS, 'Adresse du centre de données Acronis').'/api/2';
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $context->token('acronis', function () use ($context): array {
            $response = $context->guard('Connexion à Acronis', fn () => $context->http()
                ->withBasicAuth($context->credential('client_id'), $context->credential('client_secret'))
                ->asForm()
                ->post($this->api($context).'/idp/token', ['grant_type' => 'client_credentials']));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, 'Connexion à Acronis');
            }

            $token = $response->json('access_token');
            $expiresOn = (int) ($response->json('expires_on') ?? 0);

            return is_string($token) && $token !== ''
                ? [$token, $expiresOn > time() ? $expiresOn - time() : 3600]
                : throw IntegrationException::invalidResponse('Connexion à Acronis');
        });

        return $context->http()->withToken($token);
    }
}
