<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * Clé d'accès (WebAuthn) d'un utilisateur. Seule la clé publique est
 * conservée ; elle n'est jamais transmise au navigateur.
 */
#[Hidden(['credential'])]
class Passkey extends BasePasskey
{
    public function auditLabel(): string
    {
        return $this->name;
    }
}
