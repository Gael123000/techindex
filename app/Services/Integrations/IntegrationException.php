<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Erreur d'un service externe, présentable à l'administrateur.
 *
 * Le message ne reprend jamais le corps de la réponse ni la requête :
 * seulement le code HTTP, un code d'erreur court du fournisseur et une
 * explication en français.
 */
final class IntegrationException extends RuntimeException
{
    public static function fromResponse(Response $response, string $context): self
    {
        $status = $response->status();

        $explanation = match (true) {
            $status === 400 => 'demande refusée par le service',
            $status === 401 => 'identifiants invalides ou expirés',
            $status === 403 => 'accès refusé : vérifiez les droits accordés à l\'application ou au compte d\'API',
            $status === 404 => 'ressource introuvable',
            $status === 429 => 'trop de requêtes, nouvel essai à la prochaine synchronisation',
            $status >= 500 => 'service momentanément indisponible',
            default => 'réponse inattendue',
        };

        $code = self::providerCode($response);

        return new self(sprintf('%s : %s (HTTP %d%s).', $context, $explanation, $status, $code === null ? '' : ', '.$code));
    }

    public static function unreachable(string $context): self
    {
        return new self($context.' : service injoignable (délai dépassé ou problème réseau).');
    }

    public static function missingCredential(string $label): self
    {
        return new self("Identifiant manquant : « {$label} ». Complétez la configuration de l'intégration.");
    }

    public static function invalidResponse(string $context): self
    {
        return new self($context.' : réponse du service illisible.');
    }

    /**
     * Code d'erreur court renvoyé par le fournisseur (invalid_client,
     * AADSTS7000215...), s'il a une forme sûre à afficher.
     */
    private static function providerCode(Response $response): ?string
    {
        $json = $response->json();

        if (! is_array($json)) {
            return null;
        }

        $candidates = [
            $json['error']['code'] ?? null,
            is_string($json['error'] ?? null) ? $json['error'] : null,
            $json['code'] ?? null,
            $json['errorCode'] ?? null,
        ];

        // Codes Microsoft (AADSTS…) : utiles au diagnostic, sans donnée sensible.
        if (is_string($json['error_description'] ?? null) && preg_match('/\bAADSTS\d{4,8}\b/', $json['error_description'], $matches)) {
            $candidates[] = $matches[0];
        }

        $codes = array_filter($candidates, fn (mixed $code): bool => is_string($code) && preg_match('/^[A-Za-z0-9_.\-]{2,60}$/', $code) === 1);

        return $codes === [] ? null : implode(', ', array_unique($codes));
    }
}
