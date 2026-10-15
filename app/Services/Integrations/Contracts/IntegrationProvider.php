<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationAccount;
use App\Services\Integrations\Data\ExternalAccount;
use App\Services\Integrations\Data\ExternalLicense;
use App\Services\Integrations\IntegrationContext;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\ProviderDefinition;

/**
 * Connecteur vers un service externe : il liste les clients (ou
 * locataires) du prestataire et leurs licences ou abonnements.
 *
 * Un connecteur ne lit jamais la base de données ni les identifiants
 * lui-même : tout lui est fourni par le contexte. Les erreurs remontent
 * sous forme d'IntegrationException, avec un message sans donnée sensible.
 */
interface IntegrationProvider
{
    public function definition(): ProviderDefinition;

    /**
     * Vérifie les identifiants ; renvoie un résumé (« 12 clients trouvés »).
     *
     * @throws IntegrationException
     */
    public function testConnection(IntegrationContext $context): string;

    /**
     * Clients ou locataires du prestataire. Les connecteurs dont les comptes
     * sont ajoutés à la main (voir VerifiesAccounts) renvoient une liste vide.
     *
     * @return iterable<ExternalAccount>
     *
     * @throws IntegrationException
     */
    public function discoverAccounts(IntegrationContext $context): iterable;

    /**
     * Licences et abonnements d'un compte.
     *
     * @return iterable<ExternalLicense>
     *
     * @throws IntegrationException
     */
    public function fetchLicenses(IntegrationContext $context, IntegrationAccount $account): iterable;
}
