<?php

namespace App\Models;

use App\Enums\IntegrationRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exécution d'une synchronisation : déclencheur, résultat et compteurs.
 *
 * @property IntegrationRunStatus $status
 * @property array<string, int>|null $stats
 */
#[Fillable(['trigger', 'user_id', 'status', 'stats', 'message', 'started_at', 'finished_at'])]
class IntegrationRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => IntegrationRunStatus::class,
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
