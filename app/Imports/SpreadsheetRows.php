<?php

namespace App\Imports;

use DateTimeInterface;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Lit la première feuille d'un fichier Excel (.xlsx) ou CSV et renvoie ses
 * lignes indexées par numéro de ligne, avec des en-têtes normalisés
 * (« Catégorie » → « categorie », « Compte missionné » → « compte_missionne »).
 */
class SpreadsheetRows
{
    /**
     * @param  array<string, list<string>>  $aliases  colonne attendue => en-têtes acceptés
     * @return array{headers: list<string>, rows: array<int, array<string, string>>}
     */
    public static function read(string $path, string $extension, array $aliases): array
    {
        $reader = self::reader($path, $extension);
        $reader->open($path);

        $headers = [];
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $values = array_map(self::cellToString(...), $row->toArray());

                    if ($line === 1) {
                        $headers = array_map(fn (string $header) => self::resolveHeader($header, $aliases), $values);

                        continue;
                    }

                    if (implode('', $values) === '') {
                        continue;
                    }

                    $assoc = [];
                    foreach ($headers as $index => $header) {
                        if ($header !== null) {
                            $assoc[$header] = $values[$index] ?? '';
                        }
                    }
                    $rows[$line] = $assoc;
                }

                break; // Seule la première feuille contient les données.
            }
        } finally {
            $reader->close();
        }

        return ['headers' => array_values(array_filter($headers)), 'rows' => $rows];
    }

    private static function reader(string $path, string $extension): XlsxReader|CsvReader
    {
        if (strtolower($extension) === 'xlsx') {
            return new XlsxReader;
        }

        // Excel en français enregistre les CSV avec des points-virgules.
        $handle = fopen($path, 'r') ?: throw new \RuntimeException('Fichier illisible.');
        $firstLine = (string) fgets($handle);
        fclose($handle);

        return new CsvReader(new CsvOptions(
            FIELD_DELIMITER: substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',',
        ));
    }

    /**
     * @param  array<string, list<string>>  $aliases
     */
    private static function resolveHeader(string $header, array $aliases): ?string
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($header))), '_');

        foreach ($aliases as $column => $accepted) {
            if ($normalized === $column || in_array($normalized, $accepted, true)) {
                return $column;
            }
        }

        return null;
    }

    private static function cellToString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'oui' : 'non',
            // Un matricule saisi comme nombre revient en float (123.0) : on retire la partie décimale nulle.
            is_float($value) && floor($value) === $value => (string) (int) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }
}
