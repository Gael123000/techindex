<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['site_id', 'number', 'name', 'description'])]
class Vlan extends Model
{
    use Auditable, BelongsToClient;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    public function auditLabel(): string
    {
        return 'VLAN '.$this->number.' – '.$this->name;
    }

    protected function auditRelations(): array
    {
        return ['site_id' => [Site::class, 'name']];
    }
}
