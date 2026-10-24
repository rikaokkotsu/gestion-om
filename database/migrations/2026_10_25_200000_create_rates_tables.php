<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Barèmes de per diem versionnés (§ 11.2, 11.3). Le barème fourni par le
     * cahier des charges est inséré comme première version.
     */
    public function up(): void
    {
        Schema::create('rate_versions', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            // Null uniquement pour la version d'origine : elle s'applique à toute date antérieure aux suivantes.
            $table->date('effective_from')->nullable()->unique();
            // Posé au premier calcul enregistré avec cette version : elle ne se modifie plus ensuite.
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('zone_id')->constrained()->restrictOnDelete();
            $table->string('consumption_type', 16);
            // Null = tarif non renseigné ; 0 = tarif explicitement nul (§ 11.2).
            $table->unsignedBigInteger('amount')->nullable();
            // Faux = consommation sans objet pour la catégorie (ex. petit-déjeuner d'un DG).
            $table->boolean('is_applicable')->default(true);
            $table->timestamps();

            $table->unique(['rate_version_id', 'category_id', 'zone_id', 'consumption_type']);
        });

        $now = now();
        $versionId = DB::table('rate_versions')->insertGetId([
            'label' => 'Barème du cahier des charges v1.0',
            'effective_from' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categories = DB::table('categories')->pluck('id', 'code');
        $zones = DB::table('zones')->pluck('id', 'code');

        // [petit-déjeuner, déjeuner, dîner, forfait repas, nuitée] ; '-' = sans objet.
        $grid = [
            'NORD' => [
                'DG' => ['-', '-', '-', 78000, 250000],
                'DGA' => ['-', '-', '-', 65000, 250000],
                'R_HC3' => [9000, 13000, 15000, '-', 120000],
                'DIRECTEUR' => ['-', '-', '-', 52000, 170000],
                'CADRE_HC1' => [7000, 12000, 12000, '-', 85000],
                '5A' => [6500, 10000, 10000, '-', 60000],
                '5B' => [6500, 10000, 10000, '-', 60000],
            ],
            'AUTRES' => [
                'DG' => ['-', '-', '-', 78000, 200000],
                'DGA' => ['-', '-', '-', 65000, 200000],
                'R_HC3' => [6000, 9000, 10000, '-', 65000],
                'DIRECTEUR' => ['-', '-', '-', 52000, 120000],
                'CADRE_HC1' => [6000, 9000, 9000, '-', 65000],
                '5A' => [5000, 8000, 8000, '-', 50000],
                '5B' => [5000, 8000, 8000, '-', 50000],
            ],
        ];
        $types = ['PETIT_DEJ', 'DEJEUNER', 'DINER', 'FORFAIT_REPAS', 'NUITEE'];

        $rows = [];
        foreach ($grid as $zone => $byCategory) {
            foreach ($byCategory as $category => $amounts) {
                foreach ($types as $index => $type) {
                    $amount = $amounts[$index];
                    $rows[] = [
                        'rate_version_id' => $versionId,
                        'category_id' => $categories[$category],
                        'zone_id' => $zones[$zone],
                        'consumption_type' => $type,
                        'amount' => $amount === '-' ? null : $amount,
                        'is_applicable' => $amount !== '-',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }
        DB::table('rates')->insert($rows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rates');
        Schema::dropIfExists('rate_versions');
    }
};
