<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_anomalies', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Remove rows with NULL employee_id before re-adding NOT NULL constraint
        \Illuminate\Support\Facades\DB::table('payroll_anomalies')->whereNull('employee_id')->delete();

        Schema::table('payroll_anomalies', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
    }
};
