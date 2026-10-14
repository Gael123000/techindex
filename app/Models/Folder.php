<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dossier rangé dans l'arborescence d'un client (gestionnaire de mots de
 * passe ou explorateur de fichiers). La racine du client est implicite :
 * un dossier sans parent est à la racine.
 *
 * Les arborescences sont petites (quelques centaines de dossiers au plus par
 * client) : elles sont chargées en une requête puis parcourues en mémoire,
 * ce qui évite les requêtes récursives.
 */
#[Fillable(['name', 'parent_id'])]
#[Hidden(['parent_key'])]
abstract class Folder extends Model
{
    use Auditable, BelongsToClient, TracksAuthors;

    /** Profondeur maximale d'une arborescence. */
    public const MAX_DEPTH = 12;

    /** Colonnes nécessaires au parcours d'une arborescence. */
    public const TREE_COLUMNS = ['id', 'client_id', 'parent_id', 'name'];

    protected function auditExcluded(): array
    {
        return ['parent_key'];
    }

    protected function auditRelations(): array
    {
        return [
            'parent_id' => [static::class, 'name'],
        ];
    }

    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * Tous les dossiers d'un client, indexés par identifiant.
     *
     * @return Collection<int, static>
     */
    public static function treeFor(int $clientId): Collection
    {
        return static::query()
            ->where('client_id', $clientId)
            ->orderBy('name')
            ->get(self::TREE_COLUMNS)
            ->keyBy('id');
    }

    /**
     * Identifiants du dossier et de tous ses sous-dossiers.
     *
     * @param  Collection<int, static>|null  $tree
     * @return list<int>
     */
    public function selfAndDescendantIds(?Collection $tree = null): array
    {
        $tree ??= static::treeFor($this->client_id);
        $ids = [$this->id];

        for ($i = 0; $i < count($ids); $i++) {
            foreach ($tree as $folder) {
                if ($folder->parent_id === $ids[$i]) {
                    $ids[] = $folder->id;
                }
            }
        }

        return $ids;
    }

    /**
     * Chemin depuis la racine du client jusqu'au dossier (inclus).
     *
     * @param  Collection<int, static>|null  $tree
     * @return list<static>
     */
    public function ancestry(?Collection $tree = null): array
    {
        $tree ??= static::treeFor($this->client_id);
        $path = [];
        $current = $tree->get($this->id) ?? $this;

        // La limite protège d'une boucle si les données étaient incohérentes.
        while ($current !== null && count($path) <= self::MAX_DEPTH) {
            array_unshift($path, $current);
            $current = $current->parent_id === null ? null : $tree->get($current->parent_id);
        }

        return $path;
    }

    /**
     * Profondeur du dossier (1 pour un dossier à la racine du client).
     *
     * @param  Collection<int, static>|null  $tree
     */
    public function depth(?Collection $tree = null): int
    {
        return count($this->ancestry($tree));
    }

    /**
     * Hauteur du sous-arbre (1 pour un dossier sans sous-dossier).
     *
     * @param  Collection<int, static>|null  $tree
     */
    public function subtreeHeight(?Collection $tree = null): int
    {
        $tree ??= static::treeFor($this->client_id);
        $height = 1;

        foreach ($tree as $folder) {
            if ($folder->parent_id === $this->id) {
                $height = max($height, 1 + $folder->subtreeHeight($tree));
            }
        }

        return $height;
    }

    /**
     * Libellé du chemin, par exemple « Réseau / Pare-feu ».
     *
     * @param  Collection<int, static>|null  $tree
     */
    public function pathLabel(?Collection $tree = null): string
    {
        return implode(' / ', array_map(fn (Folder $folder): string => $folder->name, $this->ancestry($tree)));
    }
}
