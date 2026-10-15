<?php

namespace App\Models;

use App\Enums\PasskeyType;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * Clé d'accès (WebAuthn) d'un utilisateur. Seule la clé publique est
 * conservée ; elle n'est jamais transmise au navigateur.
 *
 * Le type est choisi dans une liste ; le nom est la précision saisie par
 * l'utilisateur (« PC du bureau »), ou à défaut le nom du type.
 *
 * @property PasskeyType $type
 */
#[Hidden(['credential'])]
class Passkey extends BasePasskey
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'type' => PasskeyType::class,
        ];
    }

    public static function nameFor(PasskeyType $type, ?string $detail): string
    {
        $detail = trim((string) $detail);

        return $detail !== '' ? $detail : $type->shortLabel();
    }

    /**
     * Précision saisie par l'utilisateur (null si le nom est celui du type).
     */
    public function detail(): ?string
    {
        return $this->name !== $this->type->shortLabel() ? $this->name : null;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
