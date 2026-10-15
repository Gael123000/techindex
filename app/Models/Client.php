<?php

namespace App\Models;

use App\Enums\ClientGroupRole;
use App\Enums\ClientPermission;
use App\Enums\RecordStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksAuthors;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Entreprise cliente documentée dans TechIndex. Un client peut être rattaché
 * à une entreprise mère (groupe d'entreprises, voir ClientGroupRole).
 *
 * @property RecordStatus $status
 * @property ClientGroupRole|null $group_role
 */
#[Fillable([
    'name', 'reference', 'description', 'account_manager_id', 'parent_id', 'group_role',
    'address_line1', 'address_line2', 'postal_code', 'city', 'country',
    'phone', 'email', 'website', 'notes',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use Auditable, HasFactory, HasTags, TracksAuthors;

    /** Niveaux d'un groupe : tête de groupe, filiale, établissement de la filiale. */
    public const MAX_GROUP_DEPTH = 3;

    protected $hidden = ['logo_path'];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'group_role' => ClientGroupRole::class,
            'parent_id' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user, ClientPermission $permission = ClientPermission::View): Builder
    {
        $clientIds = $user->clientIdsWith($permission);

        return $clientIds === null ? $query : $query->whereIn($this->qualifyColumn('id'), $clientIds);
    }

    public function isArchived(): bool
    {
        return $this->status === RecordStatus::Archived;
    }

    public function auditClientId(): ?int
    {
        // Après une suppression définitive, l'entrée ne peut plus référencer le client.
        return $this->exists ? $this->getKey() : null;
    }

    protected function auditRelations(): array
    {
        return [
            'account_manager_id' => [User::class, 'name'],
            'parent_id' => [Client::class, 'name'],
        ];
    }

    protected function auditExcluded(): array
    {
        return ['logo_path'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /**
     * Entreprise mère (siège, holding ou filiale de niveau supérieur).
     *
     * @return BelongsTo<Client, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'parent_id');
    }

    /**
     * Entreprises rattachées directement à ce client.
     *
     * @return HasMany<Client, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Client::class, 'parent_id');
    }

    /**
     * Identifiants de toutes les entreprises rattachées, à tous les niveaux.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $level = [$this->getKey()];

        // Nombre de niveaux borné : garde-fou si des données incohérentes formaient une boucle.
        for ($depth = 0; $depth < 10 && $level !== []; $depth++) {
            $level = array_values(array_diff(
                static::query()->whereIn('parent_id', $level)->pluck('id')->all(),
                $ids,
                [$this->getKey()],
            ));
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * Niveau dans le groupe : 1 pour une tête de groupe ou un client indépendant.
     */
    public function groupDepth(): int
    {
        $depth = 1;
        $parentId = $this->parent_id;
        $seen = [$this->getKey()];

        while ($parentId !== null && ! in_array($parentId, $seen, true) && $depth < 10) {
            $seen[] = $parentId;
            $depth++;
            $parentId = static::query()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }

    /**
     * Nombre de niveaux d'entreprises rattachées sous ce client (0 : aucune).
     */
    public function groupHeight(): int
    {
        $height = 0;
        $level = [$this->getKey()];
        $seen = [$this->getKey()];

        while ($height < 10) {
            $level = array_values(array_diff(static::query()->whereIn('parent_id', $level)->pluck('id')->all(), $seen));

            if ($level === []) {
                break;
            }

            $seen = [...$seen, ...$level];
            $height++;
        }

        return $height;
    }

    /**
     * @return HasMany<ClientAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClientAssignment::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ClientAssignment::class)
            ->withPivot(['id', 'can_edit', 'can_export', 'can_view_secrets', 'can_manage'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<Equipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * @return HasMany<EquipmentLink, $this>
     */
    public function equipmentLinks(): HasMany
    {
        return $this->hasMany(EquipmentLink::class);
    }

    /**
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class);
    }

    /**
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /**
     * Liens d'accès à Internet (FAI).
     *
     * @return HasMany<InternetLink, $this>
     */
    public function internetLinks(): HasMany
    {
        return $this->hasMany(InternetLink::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<License, $this>
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return HasMany<Credential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Toutes les pièces jointes du client, quel que soit l'élément rattaché.
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Pièces jointes rattachées directement à la fiche client.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function ownAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Dossiers du gestionnaire de mots de passe.
     *
     * @return HasMany<VaultFolder, $this>
     */
    public function vaultFolders(): HasMany
    {
        return $this->hasMany(VaultFolder::class);
    }

    /**
     * Dossiers de l'explorateur de fichiers.
     *
     * @return HasMany<FileFolder, $this>
     */
    public function fileFolders(): HasMany
    {
        return $this->hasMany(FileFolder::class);
    }

    /**
     * Enregistrements des fiches créées avec le constructeur.
     *
     * @return HasMany<CustomRecord, $this>
     */
    public function customRecords(): HasMany
    {
        return $this->hasMany(CustomRecord::class);
    }

    /**
     * Liaison des routes imbriquées (scopeBindings) : les types de fiches
     * sont communs à tous les clients et ne dépendent donc pas du client ;
     * tout le reste est recherché parmi les éléments du client.
     */
    public function resolveChildRouteBinding($childType, $value, $field)
    {
        if ($childType === 'recordType') {
            return RecordType::query()->where($field ?? 'key', $value)->first();
        }

        return parent::resolveChildRouteBinding($childType, $value, $field);
    }

    public function hasLogo(): bool
    {
        return $this->logo_path !== null;
    }

    /**
     * Prochaine référence interne libre (CLI-0001, CLI-0002...).
     */
    public static function nextReference(): string
    {
        $last = static::query()
            ->where('reference', 'like', 'CLI-%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last !== null && preg_match('/^CLI-(\d+)$/', $last, $matches) ? ((int) $matches[1]) + 1 : static::query()->count() + 1;

        do {
            $reference = sprintf('CLI-%04d', $number++);
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
