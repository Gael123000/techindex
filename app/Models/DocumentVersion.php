<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version figée d'une fiche documentaire (historique des versions).
 */
#[Fillable(['document_id', 'version', 'title', 'content_html', 'change_summary', 'created_by'])]
class DocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
