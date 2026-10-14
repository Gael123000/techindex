<?php

namespace App\Models;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import CSV / Excel en plusieurs étapes : chargement, correspondance des
 * colonnes, aperçu validé, puis exécution en file d'attente.
 *
 * @property ImportType $type
 * @property ImportMode $mode
 * @property ImportStatus $status
 * @property list<string>|null $headers
 * @property array<string, string|null>|null $mapping
 * @property array<string, mixed>|null $report
 */
#[Hidden(['stored_path'])]
class Import extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'mode' => ImportMode::class,
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'report' => 'array',
            'skip_invalid_rows' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
