<?php

namespace App\Actions\Passkeys;

use Laravel\Passkeys\Actions\GenerateRegistrationOptions as BaseGenerateRegistrationOptions;
use Webauthn\AuthenticatorSelectionCriteria;

/**
 * Critères d'enregistrement d'une clé d'accès.
 *
 * Dans TechIndex, une clé d'accès sert toujours pour un compte déjà identifié
 * (second facteur, confirmation, coffre) : la liste des clés autorisées est
 * fournie à chaque vérification. Une clé « découvrable » n'est donc pas
 * nécessaire, ce qui accepte tous les authentificateurs : Windows Hello,
 * Touch ID / Face ID, téléphone, clés de sécurité USB ou NFC, y compris les
 * modèles sans stockage de comptes.
 *
 * La vérification de l'utilisateur (biométrie ou code PIN) reste obligatoire :
 * une simple présence (toucher la clé) ne suffit pas.
 */
class GenerateRegistrationOptions extends BaseGenerateRegistrationOptions
{
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        );
    }
}
