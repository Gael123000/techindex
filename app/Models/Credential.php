<?php

namespace App\Models;

use App\Enums\AccessType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accès à un équipement, à un service ou à un lien Internet (FAI).
 *
 * Le gestionnaire de mots de passe et les fiches (équipement, service) lisent
 * la même table : un accès créé d'un côté apparaît immédiatement de l'autre.
 * Le dossier (folder_id) n'est qu'un rangement dans le gestionnaire.
 *
 * Le secret n'est ni assignable en masse, ni sérialisable : il ne peut être
 * écrit ou lu que par App\Secrets\CredentialSecrets, qui contrôle les droits,
 * la réauthentification et la journalisation.
 *
 * @property AccessType $access_type
 */
#[Fillable(['equipment_id', 'service_id', 'internet_link_id', 'folder_id', 'label', 'access_type', 'username', 'url', 'host', 'port', 'notes'])]
#[Hidden(['secret_ciphertext', 'secret_key_id'])]
#[Appends(['has_secret'])]
class Credential extends Model
{
    use Auditable, BelongsToClient, TracksAuthors;

    protected function casts(): array
    {
        return [
            'access_type' => AccessType::class,
            'port' => 'integer',
            'secret_updated_at' => 'datetime',
        ];
    }

    protected function auditExcluded(): array
    {
        return ['secret_updated_at'];
    }

    protected function auditRelations(): array
    {
        return [
            'equipment_id' => [Equipment::class, 'name'],
            'service_id' => [Service::class, 'name'],
            'internet_link_id' => [InternetLink::class, 'name'],
            'folder_id' => [VaultFolder::class, 'name'],
        ];
    }

    /**
     * @return BelongsTo<VaultFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(VaultFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<InternetLink, $this>
     */
    public function internetLink(): BelongsTo
    {
        return $this->belongsTo(InternetLink::class);
    }

    protected function hasSecret(): Attribute
    {
        return Attribute::get(fn (): bool => $this->secret_ciphertext !== null);
    }
}
