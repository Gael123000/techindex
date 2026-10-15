<?php

namespace App\Services\Integrations\Contracts;

use App\Services\Integrations\Data\ExternalAccount;
use App\Services\Integrations\IntegrationContext;
use App\Services\Integrations\IntegrationException;

/**
 * Connecteur dont les comptes sont ajoutés un par un (ex. locataire
 * Microsoft 365 après consentement de son administrateur).
 */
interface VerifiesAccounts
{
    /**
     * Vérifie l'accès au compte saisi (identifiant ou domaine) et renvoie
     * son identifiant définitif et son nom.
     *
     * @throws IntegrationException
     */
    public function verifyAccount(IntegrationContext $context, string $identifier): ExternalAccount;

    /**
     * Libellé et aide du champ de saisie (« Identifiant ou domaine du locataire »).
     *
     * @return array{label: string, placeholder: string, help: string}
     */
    public function accountInput(): array;
}
