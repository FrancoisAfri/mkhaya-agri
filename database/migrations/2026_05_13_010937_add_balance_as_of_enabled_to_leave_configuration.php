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
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->boolean('balance_as_of_enabled')->default(false)->after('allow_delegation');
        });
    }

    public function down(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->dropColumn('balance_as_of_enabled');
        });
    }
};
