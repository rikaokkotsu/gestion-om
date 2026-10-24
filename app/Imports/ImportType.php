<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

enum ImportType: string
{
    case Places = 'places';
    case Employees = 'employees';

    /**
     * @return array<string, list<string>>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Places => PlaceImporter::ALIASES,
            self::Employees => EmployeeImporter::ALIASES,
        };
    }

    /**
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return match ($this) {
            self::Places => PlaceImporter::REQUIRED,
            self::Employees => EmployeeImporter::REQUIRED,
        };
    }

    /**
     * En-têtes du modèle de fichier, dans l'ordre.
     *
     * @return list<string>
     */
    public function templateHeaders(): array
    {
        return match ($this) {
            self::Places => ['Nom', 'Zone'],
            self::Employees => ['Matricule', 'Nom', 'Prénom', 'Poste', 'Service', 'Catégorie', 'Affectation', 'Compte missionné', 'Validateur'],
        };
    }

    /**
     * Importe le fichier. En simulation, tout est annulé en fin de traitement
     * et seul le bilan est renvoyé.
     */
    public function import(string $path, string $extension, bool $dryRun, ?User $user): ImportReport
    {
        $report = new ImportReport($dryRun);
        ['headers' => $headers, 'rows' => $rows] = SpreadsheetRows::read($path, $extension, $this->aliases());

        $report->missingColumns = array_values(array_diff($this->requiredColumns(), $headers));
        if ($report->missingColumns !== []) {
            return $report;
        }

        DB::beginTransaction();

        try {
            match ($this) {
                self::Places => app(PlaceImporter::class)->import($rows, $report),
                self::Employees => app(EmployeeImporter::class)->import($rows, $report, $user),
            };
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $report;
    }

    /**
     * Écrit le modèle de fichier : une feuille de saisie, une feuille d'aide.
     */
    public function writeTemplate(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);

        $writer->getCurrentSheet()->setName($this === self::Places ? 'Lieux' : 'Employés');
        $writer->addRow(Row::fromValuesWithStyle($this->templateHeaders(), $bold));

        $writer->addNewSheetAndMakeItCurrent()->setName('Valeurs acceptées');
        $writer->addRow(Row::fromValuesWithStyle(['Colonne', 'Valeurs'], $bold));

        if ($this === self::Places) {
            $writer->addRow(Row::fromValues(['Zone', 'Nord, Autres (laisser vide si inconnue)']));
        } else {
            foreach (Category::query()->where('is_active', true)->orderBy('id')->get() as $category) {
                $writer->addRow(Row::fromValues(['Catégorie', "{$category->code} ({$category->label})"]));
            }
            $writer->addRow(Row::fromValues(['Affectation', 'Nom d\'un lieu déjà importé']));
            $writer->addRow(Row::fromValues(['Validateur', 'oui ou non']));
            $writer->addRow(Row::fromValues(['Matricule existant', 'La fiche est mise à jour ; une cellule vide ne remplace pas la valeur connue']));
        }

        $writer->close();
    }
}
