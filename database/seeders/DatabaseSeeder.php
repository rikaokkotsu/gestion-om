<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database for local development.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Contrôleur de démonstration',
            'email' => 'controleur@example.com',
            'password' => 'motdepasse1',
        ]);

        User::factory()->count(2)->createdBy($admin)->create();
    }
}
