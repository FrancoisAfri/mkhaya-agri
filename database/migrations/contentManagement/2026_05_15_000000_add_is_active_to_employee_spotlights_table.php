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
        Schema::table('employee_spotlights', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('month'); // ou after('une_autre_colonne')
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_spotlights', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
