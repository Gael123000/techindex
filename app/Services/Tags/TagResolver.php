<?php

namespace App\Services\Tags;

use App\Models\Tag;

/**
 * Convertit une liste de noms d'étiquettes en identifiants, en créant les
 * étiquettes inconnues (comparaison insensible à la casse).
 */
class TagResolver
{
    /**
     * @param  list<string>|null  $names
     * @return list<int>
     */
    public function idsFor(?array $names): array
    {
        $ids = [];

        foreach (array_unique(array_filter(array_map(
            static fn ($name): string => trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''),
            $names ?? [],
        ))) as $name) {
            $name = mb_substr($name, 0, 60);
            $tag = Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? Tag::query()->create(['name' => $name, 'color' => 'slate']);

            $ids[] = $tag->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{id: int, name: string, color: string}>
     */
    public function options(): array
    {
        return Tag::query()->orderBy('name')->get(['id', 'name', 'color'])->toArray();
    }
}
