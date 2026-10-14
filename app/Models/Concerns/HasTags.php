<?php

namespace App\Models\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasTags
{
    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->orderBy('name');
    }

    /**
     * Synchronise les étiquettes et consigne la modification dans l'audit.
     *
     * @param  list<int>  $tagIds
     */
    public function syncTagsWithAudit(array $tagIds): void
    {
        $before = $this->tags()->pluck('name')->sort()->values()->all();
        $this->tags()->sync($tagIds);
        $after = $this->tags()->pluck('name')->sort()->values()->all();

        if ($before !== $after && method_exists($this, 'addAuditChange')) {
            $this->addAuditChange('tags', implode(', ', $before) ?: null, implode(', ', $after) ?: null);
        }

        $this->unsetRelation('tags');
    }
}
