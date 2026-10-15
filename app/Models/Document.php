<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Fiche de la base de connaissances d'un client (procédure, guide...).
 *
 * @property DocumentStatus $status
 */
#[Fillable(['category_id', 'title', 'status', 'owner_id', 'review_interval_days'])]
class Document extends Model
{
    use Auditable, BelongsToClient, HasTags, TracksAuthors;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'last_verified_at' => 'datetime',
            'review_interval_days' => 'integer',
            'current_version' => 'integer',
        ];
    }

    protected function auditExcluded(): array
    {
        // Le contenu est historisé dans document_versions.
        return ['content_html', 'content_text', 'current_version', 'last_verified_at', 'last_verified_by'];
    }

    protected function auditRelations(): array
    {
        return [
            'category_id' => [DocumentCategory::class, 'name'],
            'owner_id' => [User::class, 'name'],
        ];
    }

    /**
     * @return BelongsTo<DocumentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_verified_by');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    /**
     * @return BelongsToMany<Equipment, $this>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class);
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    /**
     * @return BelongsToMany<InternetLink, $this>
     */
    public function internetLinks(): BelongsToMany
    {
        return $this->belongsToMany(InternetLink::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Fiches publiées dont la vérification est due (jamais vérifiées ou
     * dernière vérification plus ancienne que l'intervalle choisi).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedingReview(Builder $query): Builder
    {
        $default = (int) config('techindex.documents.default_review_interval_days');
        $now = now()->toDateTimeString();

        return $query
            ->where('status', DocumentStatus::Published)
            ->where(fn (Builder $query) => $query
                ->whereNull('last_verified_at')
                ->orWhereRaw(
                    $query->getConnection()->getDriverName() === 'sqlite'
                        ? "datetime(last_verified_at, '+' || COALESCE(review_interval_days, ?) || ' days') <= ?"
                        : 'DATE_ADD(last_verified_at, INTERVAL COALESCE(review_interval_days, ?) DAY) <= ?',
                    [$default, $now],
                ));
    }

    protected function nextReviewAt(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->last_verified_at === null) {
                return null;
            }

            $interval = $this->review_interval_days ?? (int) config('techindex.documents.default_review_interval_days');

            return Carbon::parse($this->last_verified_at)->addDays($interval)->toDateString();
        });
    }

    protected function needsReview(): Attribute
    {
        return Attribute::get(function (): bool {
            if ($this->status !== DocumentStatus::Published) {
                return false;
            }

            $next = $this->next_review_at;

            return $next === null || $next <= now()->toDateString();
        });
    }
}
