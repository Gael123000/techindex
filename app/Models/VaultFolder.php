<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dossier du gestionnaire de mots de passe. Il range des accès sans changer
 * leur rattachement (client, équipement ou service).
 */
class VaultFolder extends Folder
{
    /**
     * @return HasMany<Credential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class, 'folder_id');
    }
}
