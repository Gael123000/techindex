<?php

namespace App\Support;

use App\Models\Isp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catalogue initial des FAI (database/data/isps.php).
 *
 * Idempotent : relancé à chaque installation ou mise à jour, il ajoute les
 * opérateurs manquants sans jamais modifier ceux qui existent (un
 * administrateur a pu les corriger). Seul le logo fourni est complété ou
 * remplacé quand son fichier manque ; un logo déposé n'est jamais touché.
 */
final class IspCatalog
{
    /**
     * @return array{created: int, logos: int}
     */
    public static function sync(): array
    {
        $created = 0;
        $logos = 0;
        $now = now();

        foreach (self::entries() as [$slug, $name, $country, $website, $customerArea, $support, $businessSupport, $logo]) {
            // Requêtes directes : pas d'entrée d'historique pour des données de référence.
            $existing = DB::table('isps')->where('slug', $slug)->first(['id', 'bundled_logo']);

            if ($existing === null) {
                // Un opérateur ajouté à la main sous le même nom n'est pas dupliqué.
                if (DB::table('isps')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                    continue;
                }

                DB::table('isps')->insert([
                    'name' => $name,
                    'slug' => $slug,
                    'country' => $country,
                    'website' => $website,
                    'customer_area_url' => $customerArea,
                    'support_phone' => $support,
                    'business_support_phone' => $businessSupport,
                    'bundled_logo' => $logo,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            } elseif ($logo !== null && $existing->bundled_logo !== $logo
                && ($existing->bundled_logo === null || ! is_file(database_path(Isp::BUNDLED_LOGO_DIRECTORY.'/'.basename((string) $existing->bundled_logo))))) {
                // Logo fourni absent ou renommé depuis : le fichier livré le remplace.
                DB::table('isps')->where('id', $existing->id)->update(['bundled_logo' => $logo, 'updated_at' => $now]);
                $logos++;
            }
        }

        return ['created' => $created, 'logos' => $logos];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null, 5: string|null, 6: string|null, 7: string|null}>
     */
    public static function entries(): array
    {
        return require database_path('data/isps.php');
    }

    /**
     * Retrouve un opérateur du catalogue d'après un nom saisi librement
     * (« Orange », « orange business services », « Bouygues »...).
     */
    public static function match(?string $name): ?Isp
    {
        $needle = Str::slug((string) $name);

        if ($needle === '') {
            return null;
        }

        $isps = Isp::query()->get(['id', 'name', 'slug']);

        $exact = $isps->first(fn (Isp $isp): bool => $isp->slug === $needle || Str::slug($isp->name) === $needle);

        if ($exact !== null) {
            return $exact;
        }

        // Le nom saisi commence par le nom d'un opérateur : on retient le plus long (« Orange Business » avant « Orange »).
        return $isps
            ->filter(fn (Isp $isp): bool => str_starts_with($needle.'-', Str::slug($isp->name).'-'))
            ->sortByDesc(fn (Isp $isp): int => mb_strlen($isp->name))
            ->first();
    }
}
