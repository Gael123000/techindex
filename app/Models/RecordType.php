<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * Type de fiche du constructeur : fiche par défaut de TechIndex (is_system,
 * entity = alias du modèle) ou fiche créée par un administrateur.
 *
 * L'identifiant technique (key), la table des données (table_name) et le
 * caractère « par défaut » ne sont jamais assignables en masse : ils sont
 * fixés à la création par App\Services\Records\RecordTypeManager.
 *
 * @property list<array{key: string, title: string, description?: string|null, columns: int}>|null $sections
 * @property array<string, mixed>|null $settings
 */
#[Fillable(['name', 'plural_name', 'description', 'icon', 'tab_after', 'sort_order', 'is_active', 'sections', 'settings'])]
class RecordType extends Model
{
    use Auditable, TracksAuthors;

    /** Section créée par défaut. */
    public const DEFAULT_SECTION = 'general';

    /** Icônes proposées pour l'onglet d'une fiche (voir DynamicIcon.vue). */
    public const ICONS = [
        'clipboard-list', 'file-text', 'folder', 'briefcase', 'building-2', 'calendar', 'phone', 'printer', 'server',
        'shield', 'key-round', 'wifi', 'globe', 'cloud', 'database', 'hard-drive', 'monitor', 'smartphone', 'wrench',
        'package', 'truck', 'users', 'user-round', 'map-pin', 'receipt', 'euro', 'camera', 'network', 'router', 'plug',
        'zap', 'thermometer', 'contact', 'archive', 'box', 'book-open', 'badge-check', 'alarm-clock', 'tag', 'handshake',
    ];

    /** Onglets du client après lesquels une fiche peut être placée. */
    public const TABS = [
        'overview' => 'Vue d\'ensemble', 'sites' => 'Sites', 'contacts' => 'Contacts', 'equipment' => 'Équipements',
        'network' => 'Réseau', 'internet' => 'FAI', 'services' => 'Services', 'licenses' => 'Licences',
        'certificates' => 'Certificats', 'credentials' => 'Accès', 'documents' => 'Documentation',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'sections' => 'array',
            'settings' => 'array',
        ];
    }

    protected function auditExcluded(): array
    {
        return ['sections'];
    }

    /**
     * @return HasMany<RecordField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(RecordField::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<CustomRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(CustomRecord::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Sections de la disposition ; au moins une.
     *
     * @return list<array{key: string, title: string, description: string|null, columns: int}>
     */
    public function sectionList(): array
    {
        $sections = array_values(array_filter(
            $this->sections ?? [],
            fn ($section): bool => is_array($section) && isset($section['key']),
        ));

        if ($sections === []) {
            $sections = [['key' => self::DEFAULT_SECTION, 'title' => $this->is_system ? 'Informations complémentaires' : 'Informations', 'columns' => 2]];
        }

        return array_map(fn (array $section): array => [
            'key' => (string) $section['key'],
            'title' => (string) ($section['title'] ?? ''),
            'description' => isset($section['description']) && $section['description'] !== '' ? (string) $section['description'] : null,
            'columns' => max(1, min(3, (int) ($section['columns'] ?? 2))),
        ], $sections);
    }

    public function hasDataTable(): bool
    {
        return Schema::hasTable($this->table_name);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
