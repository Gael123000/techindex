<?php

namespace App\Secrets;

/**
 * Secret chiffré tel qu'il est stocké en base : charge chiffrée et
 * identifiant (empreinte) de la clé utilisée.
 */
final readonly class SealedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $keyId,
    ) {}
}
