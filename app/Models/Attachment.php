<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Pièce jointe stockée sur un disque privé. Le chemin de stockage est
 * généré par l'application et n'est jamais exposé au navigateur.
 *
 * L'élément de rattachement (client, équipement, site...) et le dossier de
 * l'explorateur sont indépendants : déplacer le fichier ne change pas son lien.
 */
#[Fillable(['original_name', 'description'])]
#[Hidden(['stored_path', 'checksum_sha256'])]
class Attachment extends Model
{
    use Auditable, BelongsToClient;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<FileFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }

    protected function auditRelations(): array
    {
        return [
            'folder_id' => [FileFolder::class, 'name'],
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function auditLabel(): string
    {
        return $this->original_name;
    }

    public function isInlineViewable(): bool
    {
        return in_array($this->extension, config('techindex.attachments.inline', []), true);
    }
}
