<?php

namespace Database\Seeders;

use App\Actions\SaveEmployee;
use App\Enums\AssignmentSource;
use App\Models\Category;
use App\Models\Place;
use App\Models\Service;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database for local development.
     */
    public function run(SaveEmployee $saveEmployee): void
    {
        $admin = User::factory()->create([
            'name' => 'Contrôleur de démonstration',
            'email' => 'controleur@example.com',
            'password' => 'motdepasse1',
        ]);

        User::factory()->count(2)->createdBy($admin)->create();

        // Quelques lieux et employés pour essayer la saisie des OM.
        $zones = Zone::query()->pluck('id', 'code');
        $places = collect([
            'Antananarivo' => 'AUTRES', 'Toamasina' => 'AUTRES', 'Mahajanga' => 'AUTRES',
            'Antsiranana' => 'NORD', 'Nosy Be' => 'NORD', 'Ambanja' => 'NORD',
        ])->map(fn (string $zone, string $name) => Place::create(['name' => $name, 'zone_id' => $zones[$zone]]));
        $service = Service::create(['name' => 'Contrôle de gestion']);
        $categories = Category::query()->pluck('id', 'code');

        foreach ([
            ['D001', 'Rabe', 'Hery', 'Directeur général', 'DG', 'Antananarivo', true],
            ['C014', 'Randria', 'Lova', 'Chef de service', 'CADRE_HC1', 'Antananarivo', true],
            ['R203', 'Rakoto', 'Jean', 'Superviseur régional', 'R_HC3', 'Antsiranana', false],
            ['A512', 'Rasoa', 'Fara', 'Caissière', '5A', 'Nosy Be', false],
        ] as [$matricule, $lastName, $firstName, $position, $category, $place, $validator]) {
            $saveEmployee(null, [
                'matricule' => $matricule,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'position' => $position,
                'service_id' => $service->id,
                'category_id' => $categories[$category],
                'current_place_id' => $places[$place]->id,
                'is_validator' => $validator,
            ], AssignmentSource::Manual, $admin);
        }
    }
}
