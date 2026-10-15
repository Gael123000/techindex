<?php

namespace Tests\Feature\Integrations;

use App\Enums\LicenseStatus;
use App\Models\Client;
use App\Models\Integration;
use App\Models\IntegrationAccount;
use App\Models\IntegrationRun;
use App\Models\License;
use App\Secrets\IntegrationSecrets;
use App\Services\Integrations\IntegrationSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chaque connecteur avec des réponses au format documenté par le fournisseur.
 */
class ProvidersTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->client = Client::factory()->create(['name' => 'Exemple Industries']);
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, string>  $settings
     */
    private function integration(string $provider, array $credentials, array $settings = []): Integration
    {
        $integration = Integration::query()->forceCreate(['provider' => $provider, 'is_enabled' => true, 'frequency' => 'daily', 'settings' => $settings]);
        app(IntegrationSecrets::class)->apply($integration, $credentials);
        $integration->save();

        return $integration;
    }

    private function sync(Integration $integration): IntegrationRun
    {
        $sync = app(IntegrationSync::class);

        return $sync->run($sync->start($integration->fresh(), 'manual'));
    }

    private function link(Integration $integration, string $externalId): IntegrationAccount
    {
        $account = $integration->accounts()->where('external_id', $externalId)->sole();
        $account->forceFill(['client_id' => $this->client->id])->save();

        return $account;
    }

    /**
     * @return Collection<int, License>
     */
    private function licenses(): Collection
    {
        return License::query()->where('client_id', $this->client->id)->orderBy('external_id')->get();
    }

    public function test_microsoft_365_adds_a_tenant_and_imports_licences_with_assigned_seats(): void
    {
        $tenant = '11111111-2222-3333-4444-555555555555';
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'jeton-graph', 'expires_in' => 3599]),
            'graph.microsoft.com/v1.0/organization*' => Http::response(['value' => [[
                'id' => $tenant, 'displayName' => 'Exemple Industries',
                'verifiedDomains' => [['name' => 'exemple.onmicrosoft.com', 'isInitial' => true, 'isDefault' => false], ['name' => 'exemple.fr', 'isDefault' => true, 'isInitial' => false]],
            ]]]),
            'graph.microsoft.com/v1.0/subscribedSkus*' => Http::response(['value' => [
                ['skuId' => 'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46', 'skuPartNumber' => 'SPB', 'capabilityStatus' => 'Enabled', 'consumedUnits' => 14, 'prepaidUnits' => ['enabled' => 25, 'suspended' => 0, 'warning' => 0]],
                ['skuId' => 'f30db892-07e9-47e9-837c-80727f46fd3d', 'skuPartNumber' => 'FLOW_FREE', 'capabilityStatus' => 'Enabled', 'consumedUnits' => 3, 'prepaidUnits' => ['enabled' => 10000]],
                ['skuId' => '4b9405b0-7788-4568-add1-99614e613b69', 'skuPartNumber' => 'EXCHANGESTANDARD', 'capabilityStatus' => 'Suspended', 'consumedUnits' => 0, 'prepaidUnits' => ['enabled' => 0, 'suspended' => 2]],
            ]]),
            'graph.microsoft.com/v1.0/directory/subscriptions*' => Http::response(['value' => [
                ['skuId' => 'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46', 'status' => 'Enabled', 'totalLicenses' => 25, 'isTrial' => false, 'createdDateTime' => '2025-03-01T00:00:00Z', 'nextLifecycleDateTime' => '2027-03-01T00:00:00Z'],
            ]]),
        ]);

        $integration = $this->integration('microsoft365', ['client_secret' => 'secret-graph'], ['client_id' => 'app-id', 'home_tenant' => 'prestataire.onmicrosoft.com', 'skip_free' => 'yes']);

        $this->actingAs($this->admin())->post('/admin/integrations/microsoft365/accounts', [
            'identifier' => 'exemple.onmicrosoft.com',
            'client_id' => $this->client->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $account = $integration->accounts()->sole();
        $this->assertSame($tenant, $account->external_id);
        $this->assertSame('exemple.fr', $account->domain);
        $this->assertSame($this->client->id, $account->client_id);

        $this->assertSame('success', $this->sync($integration)->status->value);

        $licenses = $this->licenses();
        $this->assertCount(2, $licenses);
        $premium = $licenses->firstWhere('external_id', 'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46');
        $this->assertSame('Microsoft 365 Business Premium', $premium->name);
        $this->assertSame(25, $premium->quantity);
        $this->assertSame(14, $premium->assigned_quantity);
        $this->assertSame('2027-03-01', $premium->expires_at->toDateString());
        $this->assertSame(LicenseStatus::Cancelled, $licenses->firstWhere('external_id', '4b9405b0-7788-4568-add1-99614e613b69')->status);
    }

    public function test_microsoft_consent_callback_checks_state_and_verifies_the_tenant(): void
    {
        $tenant = '11111111-2222-3333-4444-555555555555';
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'jeton-graph', 'expires_in' => 3599]),
            'graph.microsoft.com/v1.0/organization*' => Http::response(['value' => [['id' => $tenant, 'displayName' => 'Client Autorisé', 'verifiedDomains' => []]]]),
        ]);
        $integration = $this->integration('microsoft365', ['client_secret' => 'secret-graph'], ['client_id' => 'app-id', 'home_tenant' => 'prestataire.onmicrosoft.com']);
        $admin = $this->admin();

        $redirect = $this->actingAs($admin)->get('/admin/integrations/microsoft365/consent?client='.$this->client->id);
        $redirect->assertRedirect();
        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/v2.0/adminconsent?', $redirect->headers->get('Location'));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('app-id', $query['client_id']);

        // Retour forgé : refusé.
        $this->actingAs($admin)->get("/admin/integrations/microsoft365/callback?tenant={$tenant}&admin_consent=True&state=faux")
            ->assertSessionHas('error');
        $this->assertSame(0, $integration->accounts()->count());

        $this->actingAs($admin)->get('/admin/integrations/microsoft365/consent?client='.$this->client->id);
        $state = session('integrations.microsoft365.consent')['state'];

        $this->actingAs($admin)->get("/admin/integrations/microsoft365/callback?tenant={$tenant}&admin_consent=True&state={$state}")
            ->assertSessionHas('success');

        $account = $integration->accounts()->sole();
        $this->assertSame('Client Autorisé', $account->name);
        $this->assertSame($this->client->id, $account->client_id);
    }

    public function test_partner_center_lists_customers_and_subscriptions(): void
    {
        Http::fake([
            'login.microsoftonline.com/*/oauth2/token' => Http::response(['access_token' => 'jeton-pc', 'expires_in' => 3599]),
            'api.partnercenter.microsoft.com/v1/customers?*' => Http::response(['totalCount' => 1, 'items' => [
                ['id' => 'c1', 'companyProfile' => ['tenantId' => 'tenant-1', 'domain' => 'exemple.onmicrosoft.com', 'companyName' => 'Exemple Industries'], 'relationshipToPartner' => 'reseller'],
            ], 'links' => []]),
            'api.partnercenter.microsoft.com/v1/customers/tenant-1/subscriptions' => Http::response(['items' => [
                ['id' => 'sub-a', 'offerId' => 'CFQ7TTC0LFLZ:0002', 'offerName' => 'Microsoft 365 E5', 'friendlyName' => 'Microsoft 365 E5', 'quantity' => 10, 'status' => 'active', 'effectiveStartDate' => '2026-01-01T00:00:00Z', 'commitmentEndDate' => '2026-12-31T00:00:00Z', 'autoRenewEnabled' => true, 'termDuration' => 'P1Y'],
                ['id' => 'sub-b', 'offerName' => 'Ancienne offre', 'quantity' => 1, 'status' => 'suspended', 'termDuration' => 'P1M'],
            ]]),
        ]);

        $integration = $this->integration('partner_center', ['client_secret' => 'cle'], ['tenant' => 'prestataire.onmicrosoft.com', 'client_id' => 'app']);
        $this->sync($integration);
        $this->link($integration, 'tenant-1');
        $this->sync($integration);

        $licenses = $this->licenses();
        $this->assertCount(2, $licenses);
        $this->assertSame(10, $licenses[0]->quantity);
        $this->assertTrue($licenses[0]->auto_renewal);
        $this->assertSame('yearly', $licenses[0]->billing_period->value);
        $this->assertSame('2026-12-31', $licenses[0]->expires_at->toDateString());
        $this->assertSame(LicenseStatus::Cancelled, $licenses[1]->status);
    }

    public function test_google_workspace_signs_a_service_account_assertion(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        Http::fake([
            'oauth2.googleapis.com/token' => function (Request $request) {
                $this->assertCount(3, explode('.', $request['assertion']));

                return Http::response(['access_token' => 'jeton-google', 'expires_in' => 3600]);
            },
            'reseller.googleapis.com/apps/reseller/v1/customers/*' => Http::response(['customerId' => 'C01', 'customerDomain' => 'exemple.fr', 'postalAddress' => ['organizationName' => 'Exemple Industries']]),
            'reseller.googleapis.com/apps/reseller/v1/subscriptions*' => Http::response(['subscriptions' => [[
                'customerId' => 'C01', 'customerDomain' => 'exemple.fr', 'subscriptionId' => '123', 'skuId' => '1010020028', 'skuName' => 'Google Workspace Business Standard',
                'plan' => ['planName' => 'ANNUAL', 'commitmentInterval' => ['startTime' => '1767225600000', 'endTime' => '1798761600000']],
                'seats' => ['numberOfSeats' => 10, 'licensedNumberOfSeats' => 8], 'renewalSettings' => ['renewalType' => 'RENEW_CURRENT_USERS'], 'status' => 'ACTIVE',
            ]]]),
        ]);

        $integration = $this->integration('google_workspace', [
            'service_account' => json_encode(['client_email' => 'techindex@projet.iam.gserviceaccount.com', 'private_key' => $pem]),
        ], ['admin_email' => 'admin@revendeur.fr']);

        $this->sync($integration);
        $this->assertSame('Exemple Industries', $integration->accounts()->sole()->name);
        $this->link($integration, 'C01');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame('C01:1010020028', $license->external_id);
        $this->assertSame(10, $license->quantity);
        $this->assertSame(8, $license->assigned_quantity);
        $this->assertSame('yearly', $license->billing_period->value);
        $this->assertSame('2027-01-01', $license->expires_at->toDateString());
        $this->assertTrue($license->auto_renewal);
    }

    public function test_streamone_ion_stores_the_renewed_refresh_token(): void
    {
        Http::fake([
            'ion.tdsynnex.com/oauth/token' => Http::response(['access_token' => 'jeton-ion', 'expires_in' => 7200, 'refresh_token' => 'nouveau-jeton']),
            'ion.tdsynnex.com/api/v3/accounts/1234/customers/*' => Http::response(['customers' => [
                ['name' => 'accounts/1234/customers/56789', 'customerOrganization' => 'Exemple Industries', 'customerEmail' => 'it@exemple.fr', 'customerStatus' => 'ACTIVE'],
            ]]),
            'ion.tdsynnex.com/api/v3/accounts/1234/subscriptions*' => Http::response(['subscriptions' => [[
                'subscriptionId' => 'S-1', 'customerId' => '56789', 'cloudProviderName' => 'Microsoft', 'ccpProductInfo' => ['productDisplayName' => 'Microsoft 365 Business Basic', 'skuDisplayName' => 'Basic'],
                'subscriptionTotalLicenses' => '7', 'subscriptionStatus' => 'ACTIVE', 'subscriptionEndDate' => '2027-02-01', 'subscriptionBillingTerm' => 'Annual', 'autoRenew' => 'true',
            ]]]),
        ]);

        $integration = $this->integration('streamone_ion', ['refresh_token' => 'ancien-jeton'], ['account_id' => '1234']);
        $this->sync($integration);
        $this->link($integration, '56789');
        $this->sync($integration);

        $this->assertSame('nouveau-jeton', app(IntegrationSecrets::class)->read($integration->fresh())['refresh_token']);
        $license = $this->licenses()->sole();
        $this->assertSame(7, $license->quantity);
        $this->assertSame('Microsoft', $license->publisher);
        $this->assertTrue($license->auto_renewal);
    }

    public function test_arrowsphere_reads_customers_and_licences(): void
    {
        Http::fake([
            'xsp.arrow.com/index.php/api/customers*' => Http::response(['data' => ['customers' => [
                ['Reference' => 'XSP123', 'CompanyName' => 'Exemple Industries', 'Details' => ['DomainName' => 'exemple.fr']],
            ]], 'pagination' => ['total_page' => 1]]),
            'xsp.arrow.com/index.php/api/licenses/v2/find*' => Http::response(['results' => [['license' => [
                'partner_ref' => 'XSP987', 'customer_ref' => 'XSP123', 'vendor_name' => 'Microsoft', 'offer' => 'Microsoft 365 Business Premium', 'seat' => 6,
                'status_label' => 'activation_ok', 'start_date' => '2026-01-01T00:00:00.000Z', 'next_renewal_date' => '2027-01-01T00:00:00.000Z', 'auto_renew' => true, 'term' => 8640,
            ]]], 'pagination' => ['currentPage' => 1, 'totalPage' => 1]]),
        ]);

        $integration = $this->integration('arrowsphere', ['api_key' => 'cle-arrow']);
        $this->sync($integration);
        $this->link($integration, 'XSP123');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame(6, $license->quantity);
        $this->assertSame('yearly', $license->billing_period->value);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('apiKey', 'cle-arrow'));
    }

    public function test_ingram_micro_keeps_only_products_from_subscription_details(): void
    {
        Http::fake([
            'api.cloud.im/marketplace/eu/token' => Http::response(['token' => 'jeton-im']),
            'api.cloud.im/marketplace/eu/customers*' => Http::response(['data' => [['id' => '1000001', 'name' => 'Exemple Industries', 'status' => 'active']], 'pagination' => ['offset' => 0, 'limit' => 100, 'total' => 1]]),
            'api.cloud.im/marketplace/eu/products*' => Http::response(['data' => [['mpn' => 'MPN-1', 'name' => 'Microsoft 365 E3', 'vendor' => 'Microsoft', 'subscriptionPeriod' => ['type' => 'year', 'duration' => 1]]], 'pagination' => ['total' => 1]]),
            'api.cloud.im/marketplace/eu/subscriptions/1000177' => Http::response([
                'id' => '1000177', 'status' => 'active', 'products' => [['mpn' => 'MPN-1', 'quantity' => '5.0']],
                'fulfillmentParameters' => [['name' => 'password', 'value' => 'MotDePasseDuLocataire!']],
            ]),
            'api.cloud.im/marketplace/eu/subscriptions?*' => Http::response(['data' => [['id' => '1000177', 'customerId' => '1000001', 'status' => 'active', 'renewalStatus' => true, 'creationDate' => '2026-01-10', 'renewalDate' => '2027-01-10']], 'pagination' => ['total' => 1]]),
        ]);

        $integration = $this->integration('ingram_micro', ['username' => 'api', 'password' => 'mdp', 'subscription_key' => 'cle'], ['base_url' => 'https://api.cloud.im/marketplace/eu', 'marketplace' => 'fr']);
        $this->sync($integration);
        $this->link($integration, '1000001');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame('Microsoft 365 E3', $license->name);
        $this->assertSame(5, $license->quantity);
        $this->assertSame('2027-01-10', $license->expires_at->toDateString());
        $this->assertStringNotContainsString('MotDePasseDuLocataire', License::query()->get()->toJson().IntegrationAccount::query()->get()->toJson().IntegrationRun::query()->get()->toJson());
    }

    public function test_also_marketplace_uses_a_session_token(): void
    {
        $base = 'marketplace.also.fr/SimpleAPI/SimpleAPIService.svc/rest/';
        Http::fake([
            $base.'GetSessionToken' => Http::response('"jeton-also"'),
            $base.'GetCompany' => Http::response([['AccountId' => 100001, 'CompanyName' => 'Mon Entreprise']]),
            $base.'GetCompanies' => Http::response([['AccountId' => 100006, 'CompanyName' => 'Exemple Industries', 'AccountState' => 'Active', 'City' => 'Lyon']]),
            $base.'GetSubscriptions' => Http::response([[
                'AccountId' => 200001, 'ServiceDisplayName' => 'Microsoft 365 Business Standard', 'VendorDisplayName' => 'Microsoft', 'AccountState' => 'Active',
                'ContractEndDate' => '2027-05-01T00:00:00', 'Fields' => [['Name' => 'Quantity', 'DisplayName' => 'Quantité', 'Value' => '4']],
                'PriceableItems' => [['CommitementPeriodInMonths' => 12]],
            ]]),
        ]);

        $integration = $this->integration('also', ['username' => 'api', 'password' => 'mdp'], ['base_url' => 'https://marketplace.also.fr']);
        $this->sync($integration);
        $this->link($integration, '100006');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame(4, $license->quantity);
        $this->assertSame('yearly', $license->billing_period->value);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authenticate', 'CCPSessionId jeton-also'));
    }

    public function test_crayon_accepts_documented_and_camel_case_fields(): void
    {
        Http::fake([
            'api.crayon.com/api/v1/connect/token' => Http::response(['AccessToken' => 'jeton-crayon', 'ExpiresIn' => 20060]),
            'api.crayon.com/api/v1/organizations*' => Http::response(['Items' => [['Id' => 10, 'Name' => 'Mon organisation']], 'TotalHits' => 1]),
            'api.crayon.com/api/v1/customertenants*' => Http::response([['id' => 55, 'name' => 'Exemple Industries', 'domainPrefix' => 'exemple', 'publisher' => ['name' => 'Microsoft']]]),
            'api.crayon.com/api/v1/subscriptions*' => Http::response(['Items' => [
                ['Id' => 900, 'Name' => 'Microsoft 365 E3', 'Quantity' => 15, 'Status' => 1, 'Publisher' => ['Name' => 'Microsoft'], 'EndDate' => '2027-04-01T00:00:00', 'TermDuration' => 'P1Y', 'AutoRenewEnabled' => true],
                ['Id' => 901, 'Name' => 'Ancien', 'Quantity' => 2, 'Status' => 4],
            ], 'TotalHits' => 2]),
        ]);

        $integration = $this->integration('crayon', ['client_id' => 'id', 'client_secret' => 's', 'username' => 'u', 'password' => 'p']);
        $this->sync($integration);
        $this->link($integration, '55');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame('Microsoft 365 E3', $license->name);
        $this->assertSame(15, $license->quantity);
    }

    public function test_sophos_uses_the_partner_id_and_tenant_header(): void
    {
        Http::fake([
            'id.sophos.com/api/v2/oauth2/token' => Http::response(['access_token' => 'jeton-sophos', 'expires_in' => 3600]),
            'api.central.sophos.com/whoami/v1' => Http::response(['id' => 'partner-1', 'idType' => 'partner']),
            'api.central.sophos.com/partner/v1/tenants*' => Http::response(['pages' => ['current' => 1, 'total' => 1], 'items' => [['id' => 'tenant-1', 'name' => 'Exemple Industries', 'dataRegion' => 'eu01']]]),
            'api.central.sophos.com/licenses/v1/licenses' => Http::response(['licenses' => [
                ['id' => 'lic-1', 'licenseIdentifier' => 'LN123', 'product' => ['code' => 'CIXA-MSP', 'name' => 'Intercept X Advanced'], 'startDate' => '2026-01-15', 'endDate' => '2027-01-14', 'type' => 'usage', 'quantity' => 0, 'unlimited' => true, 'usage' => ['current' => ['count' => 42]]],
            ]]),
        ]);

        $integration = $this->integration('sophos', ['client_id' => 'id', 'client_secret' => 's']);
        $this->sync($integration);
        $this->link($integration, 'tenant-1');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame('Intercept X Advanced', $license->name);
        $this->assertNull($license->quantity);
        $this->assertSame(42, $license->assigned_quantity);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Partner-ID', 'partner-1'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant-ID', 'tenant-1'));
    }

    public function test_acronis_imports_device_counters(): void
    {
        Http::fake([
            'eu2-cloud.acronis.com/api/2/idp/token' => Http::response(['access_token' => 'jeton-acronis', 'expires_on' => time() + 7200]),
            'eu2-cloud.acronis.com/api/2/clients/*' => Http::response(['tenant_id' => 'partner-tenant']),
            'eu2-cloud.acronis.com/api/2/tenants/usages*' => Http::response(['items' => [['tenant' => 'cust-1', 'usages' => [
                ['name' => 'pw_workstations', 'usage_name' => 'workstations', 'edition' => 'pck_per_workload', 'type' => 'count', 'value' => 12, 'absolute_value' => 12, 'offering_item' => ['status' => 1, 'quota' => ['value' => 20]]],
                ['name' => 'pw_base_storage', 'usage_name' => 'storage', 'type' => 'infra', 'value' => 344064, 'offering_item' => ['status' => 1, 'quota' => ['value' => null]]],
            ]]]]),
            'eu2-cloud.acronis.com/api/2/tenants?*' => Http::response(['items' => [
                ['id' => 'cust-1', 'name' => 'Exemple Industries', 'kind' => 'customer', 'enabled' => true],
                ['id' => 'folder-1', 'name' => 'Dossier', 'kind' => 'folder'],
            ], 'paging' => ['cursors' => []]]),
        ]);

        $integration = $this->integration('acronis', ['client_id' => 'client-acronis', 'client_secret' => 's'], ['base_url' => 'https://eu2-cloud.acronis.com']);
        $this->sync($integration);
        $this->assertSame(1, $integration->accounts()->count());
        $this->link($integration, 'cust-1');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame('Acronis – Postes de travail', $license->name);
        $this->assertSame(20, $license->quantity);
        $this->assertSame(12, $license->assigned_quantity);
    }

    public function test_bitdefender_reads_licence_slots_over_json_rpc(): void
    {
        Http::fake([
            'cloud.gravityzone.bitdefender.com/api/v1.0/jsonrpc/network' => function (Request $request) {
                $params = json_decode($request->body(), true)['params'] ?? [];

                return Http::response(['id' => '1', 'jsonrpc' => '2.0', 'result' => ($params['filters']['companyType'] ?? null) === 1 && ! isset($params['parentId'])
                    ? [['id' => 'comp-1', 'name' => 'Exemple Industries']]
                    : []]);
            },
            'cloud.gravityzone.bitdefender.com/api/v1.0/jsonrpc/licensing' => Http::response(['id' => '1', 'jsonrpc' => '2.0', 'result' => [
                'subscriptionType' => 2, 'expiryDate' => '2027-06-30T00:00:00', 'usedSlots' => 18, 'totalSlots' => 25, 'licenseKey' => 'ABG5DBF',
            ]]),
        ]);

        $integration = $this->integration('bitdefender', ['api_key' => 'cle-bd']);
        $run = $this->sync($integration);
        $this->assertSame('success', $run->status->value, (string) $run->message);
        $this->link($integration, 'comp-1');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame(25, $license->quantity);
        $this->assertSame(18, $license->assigned_quantity);
        $this->assertSame('2027-06-30', $license->expires_at->toDateString());
    }

    public function test_eset_reads_licence_details(): void
    {
        Http::fake([
            'mspapi.eset.com/api/Token/Get' => Http::response(['accessToken' => 'jeton-eset', 'refreshToken' => 'r']),
            'mspapi.eset.com/api/User/Current' => Http::response(['companyId' => 'msp-1']),
            'mspapi.eset.com/api/Company/Children' => Http::response(['companies' => [['name' => 'Exemple Industries', 'publicId' => 'cust-guid', 'type' => 'Customer', 'status' => 'Active']]]),
            'mspapi.eset.com/api/Search/Licenses' => Http::response(['Search' => [['PublicLicenseKey' => 'AAA-111', 'Code' => 'EEA', 'Name' => 'ESET PROTECT Entry']], 'Paging' => ['TotalCount' => 1]]),
            'mspapi.eset.com/api/License/Detail' => Http::response(['productName' => 'ESET PROTECT Entry', 'quantity' => 30, 'usage' => 27, 'state' => 1, 'trial' => false]),
        ]);

        $integration = $this->integration('eset', ['username' => 'api', 'password' => 'mdp']);
        $this->sync($integration);
        $this->link($integration, 'cust-guid');
        $this->sync($integration);

        $license = $this->licenses()->sole();
        $this->assertSame(30, $license->quantity);
        $this->assertSame(27, $license->assigned_quantity);
        $this->assertSame(LicenseStatus::Active, $license->status);
    }

    public function test_an_account_error_does_not_stop_the_others(): void
    {
        $other = Client::factory()->create();
        Http::fake([
            'api.pax8.com/v1/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 86400]),
            'api.pax8.com/v1/companies*' => Http::response(['content' => [['id' => 'co-1', 'name' => 'A'], ['id' => 'co-2', 'name' => 'B']], 'page' => ['totalPages' => 1]]),
            'api.pax8.com/v1/subscriptions?companyId=co-1*' => Http::response(['error' => 'forbidden'], 403),
            'api.pax8.com/v1/subscriptions?companyId=co-2*' => Http::response(['content' => [['id' => 's-2', 'productName' => 'Produit', 'quantity' => 1, 'status' => 'Active']], 'page' => ['totalPages' => 1]]),
        ]);

        $integration = $this->integration('pax8', ['client_id' => 'id', 'client_secret' => 's']);
        $this->sync($integration);
        $this->link($integration, 'co-1');
        $integration->accounts()->where('external_id', 'co-2')->update(['client_id' => $other->id]);

        $run = $this->sync($integration);

        $this->assertSame('partial', $run->status->value);
        $this->assertStringContainsString('HTTP 403', (string) $run->message);
        $this->assertSame(1, License::query()->where('client_id', $other->id)->count());
        $this->assertNotNull(IntegrationAccount::query()->where('external_id', 'co-1')->value('last_error'));
    }
}
