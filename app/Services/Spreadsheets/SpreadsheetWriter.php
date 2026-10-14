<?php

namespace App\Services\Spreadsheets;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Génération de fichiers CSV / Excel protégés contre l'injection de formules.
 *
 * - Chaque texte est écrit comme cellule texte (jamais comme formule).
 * - Un texte commençant par = + - @, une tabulation ou un retour chariot est
 *   préfixé d'une apostrophe, pour qu'aucun tableur ne l'interprète.
 * - CSV : séparateur point-virgule et BOM UTF-8 (ouverture directe dans
 *   Excel en français).
 */
class SpreadsheetWriter
{
    public const CSV_DELIMITER = ';';

    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function download(string $filename, string $format, array $headings, iterable $rows): StreamedResponse
    {
        $filename .= '.'.$format;

        return response()->streamDownload(function () use ($format, $headings, $rows): void {
            $writer = $format === 'xlsx'
                ? new XlsxWriter
                : new CsvWriter(new CsvOptions(FIELD_DELIMITER: self::CSV_DELIMITER, SHOULD_ADD_BOM: true));

            $writer->openToFile('php://output');
            $writer->addRow($this->row($headings, new Style(fontBold: true)));

            foreach ($rows as $row) {
                $writer->addRow($this->row($row));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    public function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn (mixed $value): Cell => $this->cell($value, $style), array_values($values)));
    }

    public static function neutralize(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::DANGEROUS_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }

    private function cell(mixed $value, ?Style $style): Cell
    {
        return match (true) {
            $value === null || $value === '' => new EmptyCell(null, $style),
            is_int($value) || is_float($value) => new NumericCell($value, $style),
            is_bool($value) => new StringCell($value ? 'Oui' : 'Non', $style),
            $value instanceof DateTimeInterface => new StringCell($value->format('Y-m-d'), $style),
            default => new StringCell(self::neutralize((string) $value), $style),
        };
    }
}
