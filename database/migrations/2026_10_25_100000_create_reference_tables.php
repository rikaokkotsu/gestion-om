<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiels du cahier des charges (§ 4.2, 5.3, 7, 9.1). Les valeurs
     * fournies par le cahier sont insérées ici pour qu'une base neuve soit
     * utilisable dès `migrate`.
     */
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Nullable : un lieu sans zone est enregistrable, puis signalé.
            $table->foreignId('zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mission_objects', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('transport_modes', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->boolean('generates_costs');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $rows = fn (array $values) => array_map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $values);

        DB::table('zones')->insert($rows([
            ['code' => 'NORD', 'label' => 'Zone Nord'],
            ['code' => 'AUTRES', 'label' => 'Autres zones'],
        ]));

        DB::table('categories')->insert($rows([
            ['code' => 'DG', 'label' => 'Directeur Général'],
            ['code' => 'DGA', 'label' => 'Directeur Général Adjoint'],
            ['code' => 'R_HC3', 'label' => 'R HC3'],
            ['code' => 'DIRECTEUR', 'label' => 'Directeur'],
            ['code' => 'CADRE_HC1', 'label' => 'Cadre HC1'],
            ['code' => '5A', 'label' => '5A'],
            ['code' => '5B', 'label' => '5B'],
        ]));

        DB::table('mission_objects')->insert($rows([
            ['label' => 'VPC et relance'],
            ['label' => 'Intérim caisse'],
            ['label' => 'Intérim CC'],
            ['label' => 'Intérim superviseur'],
            ['label' => 'Affectation'],
        ]));

        DB::table('transport_modes')->insert($rows([
            ['label' => 'Voiture de service', 'generates_costs' => false],
            ['label' => 'Moto de service', 'generates_costs' => false],
            ['label' => 'Voiture de location', 'generates_costs' => false],
            ['label' => 'Moto de location', 'generates_costs' => false],
            ['label' => 'Taxi-brousse', 'generates_costs' => true],
            ['label' => 'Taxi-moto', 'generates_costs' => true],
            ['label' => 'Bateau', 'generates_costs' => true],
            ['label' => 'Avion', 'generates_costs' => true],
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_modes');
        Schema::dropIfExists('mission_objects');
        Schema::dropIfExists('services');
        Schema::dropIfExists('places');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('zones');
    }
};
