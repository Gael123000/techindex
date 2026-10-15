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
 * ESET PROTECT Hub / ESET MSP Administrator (API MSP) : sociétés clientes et
 * leurs licences (produit, postes, utilisation, état), avec un utilisateur
 * d'API dédié.
 */
class EsetProvider extends AbstractProvider
{
    public const KEY = 'eset';

    private const API = 'https://mspapi.eset.com/api';

    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            label: 'ESET PROTECT Hub (MSP)',
            vendor: 'ESET',
            category: IntegrationCategory::Security,
            description: 'Licences ESET de chaque société cliente : produit, postes achetés et utilisés, état, fin d\'essai.',
            accountLabel: 'client',
            credentials: [
                ProviderDefinition::text('username', 'Utilisateur d\'API'),
                ProviderDefinition::secret('password', 'Mot de passe'),
            ],
            setup: [
                'Dans ESET PROTECT Hub (Superuser) : Utilisateurs › ajoutez un utilisateur dédié à TechIndex et activez « Integrations ».',
                'Connectez-vous une première fois avec cet utilisateur pour activer le compte (le Superuser ne peut pas servir à l\'API).',
            ],
            docsUrl: 'https://help.eset.com/ema/2/api/en-US/get.html',
            initials: 'ES',
            color: '#0096a1',
        );
    }

    public function testConnection(IntegrationContext $context): string
    {
        $company = $this->ownCompanyId($context);

        return 'Connexion réussie au compte MSP ESET ('.$company.').';
    }

    public function discoverAccounts(IntegrationContext $context): iterable
    {
        $parent = $this->ownCompanyId($context);

        for ($skip = 0, $page = 0; $page < 100; $page++, $skip += 100) {
            $json = $this->json($context, 'Lecture des sociétés', fn () => $this->request($context)->post(self::API.'/Company/Children', [
                'companyId' => $parent,
                'skip' => $skip,
                'take' => 100,
            ]));

            $companies = array_filter($json['companies'] ?? [], 'is_array');

            foreach ($companies as $company) {
                $id = $this->string($company['publicId'] ?? null);

                if ($id !== null) {
                    yield new ExternalAccount($id, $this->string($company['name'] ?? null) ?? $id, $this->domain($company['email'] ?? null), [
                        'Type' => $this->string($company['type'] ?? null),
                        'Statut' => $this->string($company['status'] ?? null),
                    ]);
                }
            }

            if (count($companies) < 100) {
                break;
            }
        }
    }

    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable
    {

        for ($skip = 0, $page = 0; $page < 50; $page++, $skip += 100) {
            $json = $this->json($context, 'Lecture des licences', fn () => $this->request($context)->post(self::API.'/Search/Licenses', [
                'keyword' => '',
                'customerId' => $account->external_id,
                'skip' => $skip,
                'take' => 100,
            ]));

            $found = array_filter($json['Search'] ?? $json['search'] ?? [], 'is_array');

            foreach ($found as $item) {
                $key = $this->string($item['PublicLicenseKey'] ?? $item['publicLicenseKey'] ?? null);

                if ($key === null) {
                    continue;
                }

                $detail = $this->json($context, 'Lecture d\'une licence', fn () => $this->request($context)->post(self::API.'/License/Detail', ['publicLicenseKey' => $key]));
                $state = (int) ($detail['state'] ?? 1);
                $trial = (bool) ($detail['trial'] ?? false);

                yield new ExternalLicense(
                    externalId: $key,
                    name: ($this->string($detail['productName'] ?? null) ?? $this->string($item['Name'] ?? null) ?? 'Licence ESET').($trial ? ' (essai)' : ''),
                    publisher: 'ESET',
                    quantity: $this->int($detail['quantity'] ?? null),
                    assigned: $this->int($detail['usage'] ?? null),
                    // 0 erreur, 1 normale, 2 obsolète, 3 suspendue, 4 alerte.
                    status: in_array($state, [1, 4], true) ? LicenseStatus::Active : LicenseStatus::Cancelled,
                    expiresAt: $trial ? $this->date($detail['trialExpiration'] ?? null) : null,
                    billingPeriod: $trial ? BillingPeriod::Other : BillingPeriod::Monthly,
                    reference: $key,
                );
            }

            if (count($found) < 100) {
                break;
            }
        }
    }

    private function domain(mixed $email): ?string
    {
        return is_string($email) && str_contains($email, '@') ? $this->string(substr($email, strrpos($email, '@') + 1)) : null;
    }

    private function ownCompanyId(IntegrationContext $context): string
    {
        return $context->token('eset-company', function () use ($context): array {
            $json = $this->getJson($context, $this->request($context), self::API.'/User/Current', [], 'Lecture du compte');
            $company = $this->string($json['companyId'] ?? $json['company']['publicId'] ?? null);

            return $company !== null ? [$company, 86400] : throw IntegrationException::invalidResponse('Lecture du compte');
        });
    }

    private function request(IntegrationContext $context): PendingRequest
    {
        $token = $context->token('eset', function () use ($context): array {
            $response = $context->guard('Connexion à ESET', fn () => $context->http()->post(self::API.'/Token/Get', [
                'username' => $context->credential('username'),
                'password' => $context->credential('password'),
            ]));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, 'Connexion à ESET');
            }

            $token = $response->json('accessToken');

            // Durée de validité courte et mal documentée : jeton renouvelé toutes les minutes.
            return is_string($token) && $token !== '' ? [$token, 120] : throw IntegrationException::invalidResponse('Connexion à ESET');
        });

        return $context->http()->withToken($token);
    }
}
