<?php

namespace App\Imports;

use App\Actions\SaveEmployee;
use App\Enums\AssignmentSource;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Place;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EmployeeImporter
{
    public const ALIASES = [
        'matricule' => ['mle', 'numero_matricule'],
        'nom' => ['last_name', 'nom_de_famille'],
        'prenom' => ['prenoms', 'first_name'],
        'poste' => ['fonction', 'position'],
        'service' => ['service_rattache'],
        'categorie' => ['category', 'categorie_professionnelle'],
        'affectation' => ['affectation_actuelle', 'lieu_d_affectation'],
        'compte_missionne' => ['compte', 'compte_missionnaire'],
        'validateur' => ['role_de_validateur', 'habilite_a_valider'],
    ];

    public const REQUIRED = ['matricule', 'nom'];

    public function __construct(private SaveEmployee $save) {}

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    public function import(array $rows, ImportReport $report, ?User $user): void
    {
        $categories = Category::query()->get();
        $places = Place::query()->get()->keyBy(fn (Place $place) => Str::lower($place->name));
        $services = Service::query()->get()->keyBy(fn (Service $service) => Str::lower($service->name));
        $employees = Employee::query()->get()->keyBy('matricule');
        $seen = [];

        foreach ($rows as $line => $row) {
            $matricule = $row['matricule'] ?? '';
            $errors = [];

            if ($matricule === '') {
                $report->reject($line, ['Le matricule est vide.']);

                continue;
            }

            if (isset($seen[$matricule])) {
                $report->reject($line, ["Matricule {$matricule} en double dans le fichier (déjà ligne {$seen[$matricule]})."]);

                continue;
            }
            $seen[$matricule] = $line;

            if (($row['nom'] ?? '') === '') {
                $errors[] = 'Le nom est vide.';
            }

            $categoryId = null;
            if (($row['categorie'] ?? '') !== '') {
                $categoryId = self::findCategory($categories, $row['categorie'])?->id;
                if ($categoryId === null) {
                    $errors[] = "Catégorie inconnue : « {$row['categorie']} ».";
                }
            }

            $placeId = null;
            if (($row['affectation'] ?? '') !== '') {
                $placeId = $places[Str::lower($row['affectation'])]->id ?? null;
                if ($placeId === null) {
                    $errors[] = "Lieu d'affectation inconnu : « {$row['affectation']} ». Importez d'abord les lieux.";
                }
            }

            $isValidator = null;
            if (($row['validateur'] ?? '') !== '') {
                $isValidator = self::parseBoolean($row['validateur']);
                if ($isValidator === null) {
                    $errors[] = "Valeur de validateur non reconnue : « {$row['validateur']} ». Utilisez oui ou non.";
                }
            }

            if ($errors !== []) {
                $report->reject($line, $errors);

                continue;
            }

            $serviceId = null;
            if (($row['service'] ?? '') !== '') {
                $key = Str::lower($row['service']);
                if (! isset($services[$key])) {
                    $services[$key] = Service::create(['name' => $row['service']]);
                    $report->warn($line, "Service « {$row['service']} » ajouté au référentiel.");
                }
                $serviceId = $services[$key]->id;
            }

            // Une cellule vide ne remplace pas une information déjà connue.
            $data = array_filter([
                'last_name' => $row['nom'],
                'first_name' => $row['prenom'] ?? null,
                'position' => $row['poste'] ?? null,
                'service_id' => $serviceId,
                'category_id' => $categoryId,
                'current_place_id' => $placeId,
                'account_number' => $row['compte_missionne'] ?? null,
                'is_validator' => $isValidator,
            ], fn ($value) => $value !== null && $value !== '');

            $employee = $employees[$matricule] ?? null;

            if ($employee !== null && ! (clone $employee)->fill($data)->isDirty()) {
                $report->unchanged++;

                continue;
            }

            $wasNew = $employee === null;
            $employees[$matricule] = ($this->save)($employee, ['matricule' => $matricule, ...$data], AssignmentSource::Import, $user);
            $wasNew ? $report->created++ : $report->updated++;

            if ($wasNew && $categoryId === null) {
                $report->warn($line, "Employé {$matricule} enregistré sans catégorie.");
            }
        }
    }

    /**
     * Accepte le code (« R_HC3 »), le code avec espaces (« R HC3 ») ou le libellé.
     *
     * @param  Collection<int, Category>  $categories
     */
    public static function findCategory(Collection $categories, string $value): ?Category
    {
        $code = Str::upper((string) preg_replace('/[\s-]+/', '_', trim($value)));

        return $categories->first(fn (Category $category) => $category->code === $code || Str::lower($category->label) === Str::lower(trim($value)));
    }

    public static function parseBoolean(string $value): ?bool
    {
        return match (Str::lower(Str::ascii(trim($value)))) {
            'oui', 'o', 'yes', 'y', '1', 'x', 'vrai', 'true' => true,
            'non', 'n', 'no', '0', 'faux', 'false' => false,
            default => null,
        };
    }
}
