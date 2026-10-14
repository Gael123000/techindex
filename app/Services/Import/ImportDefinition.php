<?php

namespace App\Services\Import;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Description d'un type d'import : colonnes, normalisation, validation,
 * règle de détection des doublons et application d'une ligne.
 */
interface ImportDefinition
{
    /**
     * @return array<string, array{label: string, required: bool, example: string, help?: string, aliases?: list<string>}>
     */
    public function columns(): array;

    /**
     * Règle de détection des doublons, affichée à l'utilisateur.
     */
    public function duplicateRule(): string;

    /**
     * @param  array<string, string>  $row  Valeurs brutes indexées par clé de colonne.
     * @return array<string, mixed>
     */
    public function normalize(array $row): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * @return array<string, string>
     */
    public function messages(): array;

    /**
     * Clé utilisée pour repérer deux lignes identiques dans le même fichier.
     *
     * @param  array<string, mixed>  $data
     */
    public function duplicateKey(array $data): ?string;

    /**
     * @param  array<string, mixed>  $data
     */
    public function findExisting(array $data): ?Model;

    public function canUpdate(Model $existing, User $user): bool;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Model;

    /**
     * Met à jour uniquement les colonnes renseignées : une cellule vide ne
     * supprime jamais une information existante.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $existing, array $data, User $user): void;

    /**
     * Libellé de la ligne dans le rapport.
     *
     * @param  array<string, mixed>  $data
     */
    public function rowLabel(array $data): string;
}
