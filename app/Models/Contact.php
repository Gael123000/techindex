<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact ou interlocuteur technique d'un client, éventuellement rattaché à un site.
 */
#[Fillable([
    'site_id', 'first_name', 'last_name', 'job_title', 'email', 'phone',
    'mobile', 'is_technical', 'is_primary', 'notes',
])]
#[Appends(['full_name'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use Auditable, BelongsToClient, HasFactory;

    protected function casts(): array
    {
        return [
            'is_technical' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(($this->first_name ?? '').' '.$this->last_name));
    }

    public function auditLabel(): string
    {
        return $this->full_name;
    }

    protected function auditRelations(): array
    {
        return ['site_id' => [Site::class, 'name']];
    }
}
