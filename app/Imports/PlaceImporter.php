<?php

namespace App\Imports;

use App\Models\Place;
use App\Models\Zone;
use Illuminate\Support\Str;

class PlaceImporter
{
    public const ALIASES = [
        'nom' => ['lieu', 'name', 'nom_du_lieu'],
        'zone' => ['zone_de_rattachement'],
    ];

    public const REQUIRED = ['nom'];

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    public function import(array $rows, ImportReport $report): void
    {
        $zones = Zone::query()->pluck('id', 'code');
        $places = Place::query()->get()->keyBy(fn (Place $place) => Str::lower($place->name));
        $seen = [];

        foreach ($rows as $line => $row) {
            $name = $row['nom'] ?? '';
            $zoneValue = $row['zone'] ?? '';

            if ($name === '') {
                $report->reject($line, ['Le nom du lieu est vide.']);

                continue;
            }

            $key = Str::lower($name);
            if (isset($seen[$key])) {
                $report->reject($line, ["Lieu en double dans le fichier (déjà ligne {$seen[$key]})."]);

                continue;
            }
            $seen[$key] = $line;

            $zoneCode = self::zoneCode($zoneValue);
            if ($zoneValue !== '' && $zoneCode === null) {
                $report->reject($line, ["Zone non reconnue : « {$zoneValue} ». Valeurs acceptées : Nord, Autres."]);

                continue;
            }
            $zoneId = $zoneCode === null ? null : $zones[$zoneCode];

            $place = $places[$key] ?? null;

            if ($place === null) {
                $places[$key] = Place::create(['name' => $name, 'zone_id' => $zoneId]);
                $report->created++;
                if ($zoneId === null) {
                    $report->warn($line, "Lieu « {$name} » créé sans zone.");
                }

                continue;
            }

            // Une cellule vide ne remplace pas une zone déjà connue.
            if ($zoneId !== null && $place->zone_id !== $zoneId) {
                $place->update(['zone_id' => $zoneId]);
                $report->updated++;
            } else {
                $report->unchanged++;
            }
        }
    }

    public static function zoneCode(string $value): ?string
    {
        $normalized = trim((string) preg_replace('/[^a-z]+/', ' ', Str::lower(Str::ascii($value))));

        return match ($normalized) {
            'nord', 'zone nord', 'n' => Zone::NORD,
            'autres', 'autre', 'autres zones', 'autre zone', 'zone autres', 'a' => Zone::AUTRES,
            default => null,
        };
    }
}
