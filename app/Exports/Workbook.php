<?php

namespace App\Exports;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Classeur Excel écrit ligne à ligne, feuille par feuille. Chaque colonne a
 * un format : les montants restent des nombres, les dates de vraies dates,
 * et un texte qui commence par « = » n'est jamais lu comme une formule.
 */
final class Workbook
{
    /**
     * Largeur (en caractères) et format Excel de chaque type de colonne.
     */
    private const KINDS = [
        'text' => [18, null],
        'long' => [60, null],
        'amount' => [14, '#,##0'],
        'integer' => [10, '0'],
        'date' => [12, 'dd/mm/yyyy'],
        'datetime' => [17, 'dd/mm/yyyy hh:mm'],
    ];

    private readonly Writer $writer;

    /**
     * @var array<string, array{sheet: Sheet, styles: list<Style>, columns: int}>
     */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $this->writer = new Writer;
        $this->writer->openToFile($path);
    }

    /**
     * Ajoute une feuille, avec sa ligne d'en-tête figée et filtrable.
     *
     * @param  array<string, string>  $columns  en-tête => type de colonne (clé de KINDS)
     */
    public function sheet(string $key, string $name, array $columns): void
    {
        $sheet = $this->sheets === [] ? $this->writer->getCurrentSheet() : $this->writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $styles = [];
        foreach (array_values($columns) as $index => $kind) {
            [$width, $format] = self::KINDS[$kind];
            $sheet->setColumnWidth($width, $index + 1);
            $styles[] = $format === null ? (new Style)->withShouldWrapText($kind === 'long') : (new Style)->withFormat($format);
        }

        $header = (new Style)->withFontBold(true)->withBackgroundColor('E2E8F0')->withShouldWrapText(true);
        $this->writer->addRow(new Row(array_map(fn (string $title) => new StringCell($title, $header), array_keys($columns))));
        $this->sheets[$key] = ['sheet' => $sheet, 'styles' => $styles, 'columns' => count($columns)];
    }

    /**
     * @param  list<string|int|float|bool|DateTimeInterface|null>  $values
     */
    public function row(string $key, array $values): void
    {
        $target = $this->sheets[$key];
        if ($this->writer->getCurrentSheet() !== $target['sheet']) {
            $this->writer->setCurrentSheet($target['sheet']);
        }

        $cells = [];
        foreach ($values as $index => $value) {
            $style = $target['styles'][$index] ?? null;
            $cells[] = is_string($value) ? new StringCell($value, $style) : Cell::fromValue($value, $style);
        }
        $this->writer->addRow(new Row($cells));
    }

    /**
     * Feuille de lignes libres (critères d'un export, par exemple).
     *
     * @param  list<string>  $lines
     */
    public function notes(string $name, array $lines): void
    {
        $sheet = $this->writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $sheet->setColumnWidth(100, 1);
        foreach ($lines as $line) {
            $this->writer->addRow(new Row([new StringCell($line, null)]));
        }
    }

    public function close(): void
    {
        foreach ($this->sheets as $target) {
            $rows = $target['sheet']->getWrittenRowCount();
            if ($rows > 1) {
                $target['sheet']->setAutoFilter(new AutoFilter(0, 1, $target['columns'] - 1, $rows));
            }
        }
        $this->writer->close();
    }
}
