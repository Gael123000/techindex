<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dossier de l'explorateur de fichiers. Il range des pièces jointes sans
 * changer l'élément auquel elles sont rattachées.
 */
class FileFolder extends Folder
{
    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'folder_id');
    }
}
