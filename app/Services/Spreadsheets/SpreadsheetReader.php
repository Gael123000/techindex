<?php

namespace App\Services\Spreadsheets;

use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Lecture de la première feuille d'un fichier CSV ou Excel (XLSX).
 *
 * CSV : séparateur détecté (; , ou tabulation) et encodage Windows-1252
 * accepté (fichiers enregistrés par Excel en français).
 */
class SpreadsheetReader
{
    /**
     * @return array{headers: list<string>, rows: list<array{line: int, values: list<string>}>, truncated: bool}
     */
    public function read(string $path, string $extension, int $maxRows): array
    {
        if (! is_dir(storage_path('app/private/tmp'))) {
            mkdir(storage_path('app/private/tmp'), 0770, true);
        }

        $reader = match (strtolower($extension)) {
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, tempFolder: storage_path('app/private/tmp'))),
            'csv', 'txt' => new CsvReader(new CsvOptions(
                FIELD_DELIMITER: $this->detectDelimiter($path),
                ENCODING: $this->detectEncoding($path),
            )),
            default => throw new RuntimeException('Format de fichier non pris en charge.'),
        };

        $reader->open($path);

        $headers = null;
        $rows = [];
        $truncated = false;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $values = $this->values($row);

                    if ($headers === null) {
                        if (array_filter($values, fn (string $value): bool => $value !== '') === []) {
                            continue;
                        }

                        $headers = array_map(fn (string $value): string => trim($value), $values);

                        continue;
                    }

                    if (array_filter($values, fn (string $value): bool => $value !== '') === []) {
                        continue;
                    }

                    if (count($rows) >= $maxRows) {
                        $truncated = true;
                        break;
                    }

                    $rows[] = ['line' => $line, 'values' => $values];
                }

                break; // Première feuille uniquement.
            }
        } finally {
            $reader->close();
        }

        return ['headers' => $headers ?? [], 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * @return list<string>
     */
    private function values(Row $row): array
    {
        $values = [];

        foreach ($row->cells as $cell) {
            $value = $cell->getValue();

            $values[] = match (true) {
                $value === null => '',
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                is_bool($value) => $value ? '1' : '0',
                is_float($value) && floor($value) === $value && abs($value) < 1e15 => sprintf('%.0f', $value),
                is_scalar($value) => trim((string) $value),
                default => '',
            };
        }

        return $values;
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $line = $handle === false ? '' : (string) fgets($handle, 65536);

        if ($handle !== false) {
            fclose($handle);
        }

        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts) ?: ';';
    }

    private function detectEncoding(string $path): string
    {
        $chunk = (string) file_get_contents($path, false, null, 0, 65536);

        // Un caractère multioctet peut être coupé en fin de bloc.
        if (strlen($chunk) === 65536) {
            $chunk = substr($chunk, 0, -3);
        }

        return mb_check_encoding($chunk, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }
}
