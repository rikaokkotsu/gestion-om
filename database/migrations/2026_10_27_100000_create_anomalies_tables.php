<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contrôle des OM et anomalies (§ 13). Une anomalie ne modifie jamais
     * l'OM contrôlé : elle le signale, puis suit son propre traitement.
     */
    public function up(): void
    {
        // Une exécution du moteur, sur un OM ou sur un lot d'OM.
        Schema::create('control_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 16);
            $table->string('status', 16)->default('QUEUED');
            $table->foreignId('mission_order_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('filters')->nullable();
            $table->foreignId('launched_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('anomalies_created')->default(0);
            $table->unsignedInteger('anomalies_closed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_order_id')->constrained()->restrictOnDelete();
            // Missionnaire concerné ; l'employé reste connu si le missionnaire est retiré de l'OM.
            $table->foreignId('mission_order_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 1);
            // Règle du catalogue à l'origine du signalement ; facultative pour un signalement manuel.
            $table->string('rule_code', 16)->nullable();
            // Précise l'objet du signalement quand une règle en produit plusieurs (étape, type de repas…).
            $table->string('target', 64)->default('');
            $table->string('source', 8);
            $table->string('nature', 16)->nullable();
            $table->string('priority', 8);
            $table->string('state', 16);
            $table->text('description');
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('control_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamp('last_detected_at')->nullable();
            // Renseignée quand le contrôle ne retrouve plus la situation signalée.
            $table->timestamp('no_longer_detected_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['mission_order_id', 'rule_code']);
            $table->index(['state', 'detected_at']);
            $table->index('employee_id');
        });

        // Historique des actions sur une anomalie, en ajout seul.
        Schema::create('anomaly_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anomaly_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16)->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('changes')->nullable();
            $table->timestamp('created_at');
        });

        // Règles désactivées par les contrôleurs ; une règle sans ligne est active.
        Schema::create('control_rule_settings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->boolean('is_enabled');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('mission_orders', function (Blueprint $table) {
            $table->timestamp('last_controlled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mission_orders', function (Blueprint $table) {
            $table->dropColumn('last_controlled_at');
        });
        Schema::dropIfExists('control_rule_settings');
        Schema::dropIfExists('anomaly_events');
        Schema::dropIfExists('anomalies');
        Schema::dropIfExists('control_runs');
    }
};
