<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fournisseur d'accès à Internet du catalogue, commun à tous les clients.
 *
 * Le logo est soit fourni avec TechIndex (bundled_logo, fichier de
 * database/data/isp-logos), soit déposé par un administrateur (logo_path,
 * disque privé) ; le logo déposé est prioritaire.
 */
#[Fillable([
    'name', 'slug', 'country', 'website', 'customer_area_url', 'status_url',
    'support_phone', 'business_support_phone', 'support_email', 'notes', 'is_active',
])]
class Isp extends Model
{
    use Auditable;

    /** Dossier des logos livrés avec l'application. */
    public const BUNDLED_LOGO_DIRECTORY = 'data/isp-logos';

    protected $hidden = ['logo_path'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function auditExcluded(): array
    {
        return ['logo_path', 'bundled_logo'];
    }

    /**
     * @return HasMany<InternetLink, $this>
     */
    public function internetLinks(): HasMany
    {
        return $this->hasMany(InternetLink::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasLogo(): bool
    {
        return $this->logo_path !== null || $this->bundledLogoPath() !== null;
    }

    /**
     * Chemin absolu du logo fourni, s'il existe. Le nom de fichier est
     * contrôlé (pas de séparateur de dossier) avant toute lecture.
     */
    public function bundledLogoPath(): ?string
    {
        $file = $this->bundled_logo;

        if ($file === null || preg_match('/^[a-z0-9][a-z0-9._-]*\.(svg|png|jpg)$/', $file) !== 1) {
            return null;
        }

        $path = database_path(self::BUNDLED_LOGO_DIRECTORY.'/'.$file);

        return is_file($path) ? $path : null;
    }

    public function logoUrl(): ?string
    {
        return $this->hasLogo() ? route('isps.logo', ['isp' => $this->id, 'v' => $this->updated_at?->getTimestamp()]) : null;
    }

    /**
     * Option de liste déroulante avec logo, pour les formulaires.
     *
     * @return array{value: int, label: string, logo_url: string|null, active: bool}
     */
    public function toOption(): array
    {
        return [
            'value' => $this->id,
            'label' => $this->name,
            'logo_url' => $this->logoUrl(),
            'active' => $this->is_active,
        ];
    }
}
