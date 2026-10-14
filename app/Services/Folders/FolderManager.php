<?php

namespace App\Services\Folders;

use App\Models\Client;
use App\Models\Folder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Règles communes aux arborescences de dossiers (gestionnaire de mots de
 * passe et explorateur de fichiers) :
 * - un dossier appartient au client de l'URL, comme son parent ;
 * - deux dossiers frères ne portent pas le même nom (casse ignorée) ;
 * - un dossier ne peut pas être déplacé dans lui-même ou un de ses descendants ;
 * - la profondeur est limitée (Folder::MAX_DEPTH).
 */
class FolderManager
{
    /**
     * Caractères interdits dans un nom : séparateurs de chemin, caractères de
     * contrôle et caractères réservés par Windows (export ou synchronisation).
     */
    public const NAME_PATTERN = '/^[^\/\\\\:*?"<>|\x00-\x1F\x7F]+$/u';

    /**
     * Règles de validation d'un nom de dossier.
     *
     * @return list<mixed>
     */
    public static function nameRules(): array
    {
        return ['required', 'string', 'max:100', 'regex:'.self::NAME_PATTERN, 'not_in:.,..'];
    }

    /**
     * Messages de validation lisibles.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.regex' => 'Le nom ne peut pas contenir les caractères / \\ : * ? " < > |.',
            'name.not_in' => 'Ce nom de dossier n\'est pas autorisé.',
        ];
    }

    /**
     * @template T of Folder
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function create(string $class, Client $client, string $name, ?Folder $parent): Folder
    {
        $name = $this->normalize($name);
        $tree = $class::treeFor($client->id);

        if ($parent !== null && $parent->depth($tree) >= Folder::MAX_DEPTH) {
            $this->fail('parent_id', 'Profondeur maximale atteinte ('.Folder::MAX_DEPTH.' niveaux).');
        }

        $this->ensureAvailable($tree, $parent?->id, $name);

        /** @var T $folder */
        $folder = new $class(['name' => $name, 'parent_id' => $parent?->id]);
        $folder->client()->associate($client);

        $this->save($folder);

        return $folder;
    }

    /**
     * Renomme et/ou déplace un dossier.
     */
    public function update(Folder $folder, string $name, ?Folder $parent): void
    {
        $name = $this->normalize($name);
        $tree = $folder::treeFor($folder->client_id);

        if ($parent !== null && in_array($parent->id, $folder->selfAndDescendantIds($tree), true)) {
            $this->fail('parent_id', 'Un dossier ne peut pas être déplacé dans lui-même ou dans un de ses sous-dossiers.');
        }

        $parentDepth = $parent === null ? 0 : $parent->depth($tree);

        if ($parentDepth + $folder->subtreeHeight($tree) > Folder::MAX_DEPTH) {
            $this->fail('parent_id', 'Profondeur maximale dépassée ('.Folder::MAX_DEPTH.' niveaux).');
        }

        $this->ensureAvailable($tree, $parent?->id, $name, $folder->id);

        $folder->fill(['name' => $name, 'parent_id' => $parent?->id]);
        $this->save($folder);
    }

    /**
     * Retrouve ou crée le sous-dossier "name" de "parent" (import d'un dossier).
     *
     * @template T of Folder
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function findOrCreate(string $class, Client $client, string $name, ?Folder $parent): Folder
    {
        $name = $this->normalize($name);

        $existing = $class::query()
            ->where('client_id', $client->id)
            ->where('parent_key', $parent?->id ?? 0)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create($class, $client, $name, $parent);
        } catch (ValidationException $exception) {
            // Créé entre-temps par un envoi parallèle : on le réutilise.
            $existing = $class::query()
                ->where('client_id', $client->id)
                ->where('parent_key', $parent?->id ?? 0)
                ->where('name', $name)
                ->first();

            return $existing ?? throw $exception;
        }
    }

    /**
     * Nom libre parmi les frères : « Nom », puis « Nom (2) », « Nom (3) »...
     *
     * @param  Collection<int, Folder>  $tree
     */
    public function availableName(Collection $tree, ?int $parentId, string $name, ?int $ignoreId = null): string
    {
        $candidate = $name;

        for ($i = 2; $this->isTaken($tree, $parentId, $candidate, $ignoreId); $i++) {
            $suffix = " ({$i})";
            $candidate = mb_substr($name, 0, 100 - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /**
     * @param  Collection<int, Folder>  $tree
     */
    private function ensureAvailable(Collection $tree, ?int $parentId, string $name, ?int $ignoreId = null): void
    {
        if ($this->isTaken($tree, $parentId, $name, $ignoreId)) {
            $this->fail('name', 'Un dossier porte déjà ce nom à cet emplacement.');
        }
    }

    /**
     * @param  Collection<int, Folder>  $tree
     */
    private function isTaken(Collection $tree, ?int $parentId, string $name, ?int $ignoreId): bool
    {
        $needle = mb_strtolower($name);

        return $tree->contains(fn (Folder $folder): bool => $folder->parent_id === $parentId
            && $folder->id !== $ignoreId
            && mb_strtolower($folder->name) === $needle);
    }

    private function save(Folder $folder): void
    {
        try {
            $folder->save();
        } catch (UniqueConstraintViolationException) {
            $this->fail('name', 'Un dossier porte déjà ce nom à cet emplacement.');
        }
    }

    private function normalize(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
