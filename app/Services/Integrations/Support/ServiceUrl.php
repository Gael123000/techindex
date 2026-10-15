<?php

namespace App\Services\Integrations\Support;

use App\Services\Integrations\IntegrationException;

/**
 * Adresse d'API saisie par l'administrateur (région, centre de données) :
 * HTTPS obligatoire et domaine limité à ceux du fournisseur, pour qu'une
 * configuration ne puisse pas faire appeler un autre serveur (SSRF).
 */
final class ServiceUrl
{
    /**
     * @param  list<string>  $allowedDomains  domaines autorisés (sous-domaines compris)
     */
    public static function https(string $url, array $allowedDomains, string $label): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        $allowed = ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['port'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && $host !== ''
            && collect($allowedDomains)->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));

        if (! $allowed) {
            throw new IntegrationException("{$label} refusée : adresse HTTPS d'un domaine ".implode(' ou ', $allowedDomains).' attendue.');
        }

        return $url;
    }
}
