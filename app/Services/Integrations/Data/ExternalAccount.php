<?php

namespace App\Services\Integrations\Data;

/**
 * Client ou locataire tel que décrit par le service externe.
 */
final readonly class ExternalAccount
{
    /**
     * @param  array<string, scalar|null>  $metadata  détails affichés (jamais de secret)
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $domain = null,
        public array $metadata = [],
    ) {}
}
