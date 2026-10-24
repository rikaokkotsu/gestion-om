<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordres de mission tels qu'ils figurent sur le document (§ 5). Presque
     * tout est nullable : un document incomplet ou incohérent s'enregistre,
     * puis le moteur de contrôle le signale. Montants en ariary entiers ;
     * dates et heures locales (Madagascar), comme sur le document.
     */
    public function up(): void
    {
        Schema::create('mission_orders', function (Blueprint $table) {
            $table->id();
            // Non unique : un doublon de référence sur le document doit pouvoir être saisi, puis signalé.
            $table->string('reference', 64)->nullable()->index();
            $table->string('type', 16)->nullable();
            $table->foreignId('mission_object_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('departure_at')->nullable()->index();
            $table->dateTime('return_at')->nullable();
            $table->foreignId('departure_place_id')->nullable()->constrained('places')->restrictOnDelete();
            $table->foreignId('destination_place_id')->nullable()->constrained('places')->restrictOnDelete();
            // Durée déclarée sur le document, en jours ; la durée selon les dates est calculée à la lecture.
            $table->integer('declared_duration')->nullable();
            // Montant global des frais supplémentaires, non multiplié par le nombre de missionnaires (§ 12.1).
            $table->bigInteger('additional_costs')->nullable();
            $table->bigInteger('assignment_allowance')->nullable();
            $table->foreignId('validator_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->string('administrative_status', 16)->default('PENDING');
            $table->string('control_status', 16)->default('NOT_CONTROLLED');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Ventilation facultative du montant global des frais supplémentaires.
        Schema::create('mission_order_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('nature', 16);
            $table->string('label')->nullable();
            $table->bigInteger('amount')->nullable();
        });

        // Étapes de transport, ordonnées dans chaque sens ; aucune étape = aucun moyen de transport.
        Schema::create('transport_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 8);
            $table->unsignedSmallInteger('position');
            $table->foreignId('transport_mode_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('from_place_id')->nullable()->constrained('places')->restrictOnDelete();
            $table->foreignId('to_place_id')->nullable()->constrained('places')->restrictOnDelete();
            $table->bigInteger('cost')->nullable();

            $table->unique(['mission_order_id', 'direction', 'position']);
        });

        // Un missionnaire de l'OM, avec ses propres données (§ 5.5).
        Schema::create('mission_order_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            // Catégorie retenue sur l'OM, qui peut différer de celle de la fiche employé.
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('declared_per_diem')->nullable();
            // Résultat du calcul, conservé tel quel : il n'écrase jamais le déclaré.
            $table->bigInteger('calculated_per_diem')->nullable();
            $table->boolean('calculation_complete')->nullable();
            $table->jsonb('calculation')->nullable();
            $table->foreignId('rate_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['mission_order_id', 'employee_id']);
        });

        // Quantités attribuées par type et par zone ; une quantité négative est signalée, pas refusée.
        Schema::create('consumption_quantities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_employee_id')->constrained()->cascadeOnDelete();
            $table->string('consumption_type', 16);
            $table->foreignId('zone_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');

            $table->unique(['mission_order_employee_id', 'consumption_type', 'zone_id']);
        });

        // Total attribué par type, tel que déclaré, à comparer à la somme des zones.
        Schema::create('consumption_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_employee_id')->constrained()->cascadeOnDelete();
            $table->string('consumption_type', 16);
            $table->integer('total');

            $table->unique(['mission_order_employee_id', 'consumption_type']);
        });

        Schema::table('assignment_histories', function (Blueprint $table) {
            $table->foreign('mission_order_id')->references('id')->on('mission_orders')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assignment_histories', function (Blueprint $table) {
            $table->dropForeign(['mission_order_id']);
        });
        Schema::dropIfExists('consumption_totals');
        Schema::dropIfExists('consumption_quantities');
        Schema::dropIfExists('mission_order_employees');
        Schema::dropIfExists('transport_legs');
        Schema::dropIfExists('mission_order_costs');
        Schema::dropIfExists('mission_orders');
    }
};
