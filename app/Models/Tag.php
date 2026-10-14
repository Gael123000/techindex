<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Étiquette partagée entre clients, équipements, services et documents.
 */
#[Fillable(['name', 'color'])]
class Tag extends Model
{
    use Auditable;

    public const COLORS = ['slate', 'blue', 'teal', 'green', 'amber', 'orange', 'red', 'violet'];

    /**
     * @return MorphToMany<Client, $this>
     */
    public function clients(): MorphToMany
    {
        return $this->morphedByMany(Client::class, 'taggable');
    }

    /**
     * @return MorphToMany<Equipment, $this>
     */
    public function equipment(): MorphToMany
    {
        return $this->morphedByMany(Equipment::class, 'taggable');
    }

    /**
     * @return MorphToMany<Service, $this>
     */
    public function services(): MorphToMany
    {
        return $this->morphedByMany(Service::class, 'taggable');
    }

    /**
     * @return MorphToMany<Document, $this>
     */
    public function documents(): MorphToMany
    {
        return $this->morphedByMany(Document::class, 'taggable');
    }
}
