<?php

namespace App\Services\Integrations\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Noms commerciaux des licences Microsoft (« SPB » → « Microsoft 365
 * Business Premium »).
 *
 * Une liste des produits courants est fournie avec TechIndex
 * (database/data/microsoft-skus.json) ; la liste complète publiée par
 * Microsoft peut être téléchargée et conservée dans le stockage local
 * (commande techindex:integrations-sync --refresh-catalogs).
 */
class MicrosoftSkuCatalog
{
    /** Fichier officiel « Product names and service plan identifiers for licensing ». */
    public const SOURCE_URL = 'https://download.microsoft.com/download/e/3/e/e3e9faf2-f28b-490a-9ada-c6089a1fc5b0/Product%20names%20and%20service%20plan%20identifiers%20for%20licensing.csv';

    private const STORED = 'integrations/microsoft-skus.json';

    /** @var array<string, string>|null */
    private ?array $names = null;

    public function name(string $skuPartNumber, ?string $skuId = null): string
    {
        $names = $this->names();

        return $names[$skuPartNumber]
            ?? $names[mb_strtolower($skuPartNumber)]
            ?? ($skuId !== null ? ($names[mb_strtolower($skuId)] ?? null) : null)
            ?? $skuPartNumber;
    }

    /**
     * Télécharge la liste officielle ; renvoie le nombre de produits connus.
     */
    public function refresh(): int
    {
        $response = Http::timeout(60)->get(self::SOURCE_URL);

        if ($response->failed()) {
            return 0;
        }

        $names = $this->parseCsv($response->body());

        if ($names !== []) {
            Storage::disk('local')->put(self::STORED, json_encode($names, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->names = null;
        }

        return count($names);
    }

    /**
     * Une ligne par plan de service : on garde un nom par produit, indexé par
     * référence (String_Id) et par identifiant (GUID).
     *
     * @return array<string, string>
     */
    public function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? '') ?: [];
        $header = str_getcsv((string) array_shift($lines), escape: '');
        $nameIndex = array_search('Product_Display_Name', $header, true);
        $idIndex = array_search('String_Id', $header, true);
        $guidIndex = array_search('GUID', $header, true);

        if ($nameIndex === false || $idIndex === false) {
            return [];
        }

        $names = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line, escape: '');
            $name = trim((string) ($row[$nameIndex] ?? ''));
            $id = trim((string) ($row[$idIndex] ?? ''));

            if ($name === '' || $id === '' || mb_strlen($name) > 200) {
                continue;
            }

            $names[$id] ??= $name;

            if ($guidIndex !== false && preg_match('/^[0-9a-f-]{36}$/i', (string) ($row[$guidIndex] ?? '')) === 1) {
                $names[mb_strtolower((string) $row[$guidIndex])] ??= $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        // Liste fournie dans database/data (inclus dans toutes les archives,
        // contrairement à resources/) ; son absence ne doit pas bloquer une synchronisation.
        $bundledPath = $this->bundledPath();
        $bundled = is_readable($bundledPath) ? $this->decode((string) file_get_contents($bundledPath)) : [];
        $stored = [];

        try {
            if (Storage::disk('local')->exists(self::STORED)) {
                $stored = $this->decode((string) Storage::disk('local')->get(self::STORED));
            }
        } catch (Throwable) {
            $stored = [];
        }

        // Noms en français de la liste fournie, complétés par la liste officielle.
        $names = [...$stored, ...$bundled];

        foreach ($bundled as $id => $name) {
            $names[mb_strtolower($id)] = $name;
        }

        return $this->names = $names;
    }

    protected function bundledPath(): string
    {
        return database_path('data/microsoft-skus.json');
    }

    /**
     * @return array<string, string>
     */
    private function decode(string $json): array
    {
        $value = json_decode($json, true);

        return is_array($value) ? array_filter($value, fn ($name, $id): bool => is_string($name) && is_string($id), ARRAY_FILTER_USE_BOTH) : [];
    }
}
