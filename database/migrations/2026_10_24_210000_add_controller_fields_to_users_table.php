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
        Schema::table('users', function (Blueprint $table) {
            // Compte créateur : seul lui peut modifier ou désactiver ce compte.
            $table->foreignId('created_by_id')->nullable()->after('password')->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('created_by_id');
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropColumn(['is_active', 'deactivated_at']);
        });
    }
};
