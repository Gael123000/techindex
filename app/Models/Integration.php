<?php

namespace App\Models;

use App\Enums\IntegrationFrequency;
use App\Enums\IntegrationRunStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksAuthors;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuration d'un connecteur du catalogue (une par connecteur).
 *
 * Les identifiants d'API ne sont lus et écrits que par
 * App\Secrets\IntegrationSecrets ; seule leur forme chiffrée est stockée.
 *
 * @property IntegrationFrequency $frequency
 * @property IntegrationRunStatus|null $last_status
 * @property array<string, mixed>|null $settings
 */
#[Fillable(['provider', 'is_enabled', 'settings', 'frequency'])]
#[Hidden(['credentials_ciphertext', 'credentials_key_id'])]
class Integration extends Model
{
    use Auditable, TracksAuthors;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'frequency' => IntegrationFrequency::class,
            'last_status' => IntegrationRunStatus::class,
            'last_synced_at' => 'datetime',
            'credentials_updated_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return app(IntegrationRegistry::class)->label($this->provider);
    }

    protected function auditExcluded(): array
    {
        return ['credentials_ciphertext', 'credentials_key_id', 'last_synced_at', 'last_status', 'last_message'];
    }

    public function hasCredentials(): bool
    {
        return $this->credentials_ciphertext !== null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * @return HasMany<IntegrationAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(IntegrationAccount::class);
    }

    /**
     * @return HasMany<IntegrationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(IntegrationRun::class);
    }
}
