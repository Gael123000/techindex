<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Enregistrement d'une fiche créée avec le constructeur.
 *
 * Les valeurs des champs sont dans la table de la fiche (cx_...), une ligne
 * par enregistrement (record_id) ; elles sont lues et écrites par
 * App\Services\Records\RecordValues. Le titre et le texte de recherche sont
 * recopiés ici pour les listes et la recherche globale.
 *
 * @property RecordStatus $status
 */
#[Fillable(['site_id', 'status'])]
class CustomRecord extends Model
{
    use Auditable, BelongsToClient, HasTags, TracksAuthors;

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    protected function auditExcluded(): array
    {
        // Le titre et le texte de recherche sont dérivés des champs, déjà journalisés.
        return ['title', 'search_text', 'record_type_id'];
    }

    protected function auditRelations(): array
    {
        return ['site_id' => [Site::class, 'name']];
    }

    /**
     * @return BelongsTo<RecordType, $this>
     */
    public function recordType(): BelongsTo
    {
        return $this->belongsTo(RecordType::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function auditLabel(): string
    {
        $title = (string) ($this->getAttributes()['title'] ?? '#'.$this->getKey());

        return $this->relationLoaded('recordType') && $this->recordType !== null
            ? $this->recordType->name.' – '.$title
            : $title;
    }
}
