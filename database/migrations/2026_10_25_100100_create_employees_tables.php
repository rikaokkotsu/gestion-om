<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 64)->unique();
            $table->string('last_name');
            // Les autres informations sont nullables : une fiche incomplète
            // s'enregistre, et le contrôle EMP-03 la signale.
            $table->string('first_name')->nullable();
            $table->string('position')->nullable();
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('current_place_id')->nullable()->constrained('places')->restrictOnDelete();
            $table->string('account_number')->nullable();
            $table->boolean('is_validator')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('assignment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_place_id')->nullable()->constrained('places')->restrictOnDelete();
            $table->foreignId('new_place_id')->nullable()->constrained('places')->restrictOnDelete();
            // Nullable : la date d'une affectation initiale (création, import) est souvent inconnue.
            $table->date('effective_date')->nullable();
            // Origine du changement : saisie manuelle, import, ou OM de type Affectation.
            $table->string('source', 16);
            // Clé étrangère vers les OM ajoutée à l'étape 4.
            $table->unsignedBigInteger('mission_order_id')->nullable()->index();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_histories');
        Schema::dropIfExists('employees');
    }
};
