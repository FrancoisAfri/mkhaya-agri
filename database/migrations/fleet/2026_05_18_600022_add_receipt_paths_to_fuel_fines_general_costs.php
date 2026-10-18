<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_fuel_logs', function (Blueprint $table) {
            $table->text('receipt_path')->nullable()->after('notes');
        });

        Schema::table('fleet_vehicle_general_costs', function (Blueprint $table) {
            $table->text('receipt_path')->nullable()->after('description');
        });

        Schema::table('fleet_fines', function (Blueprint $table) {
            $table->text('infringement_notice_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_fuel_logs', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });

        Schema::table('fleet_vehicle_general_costs', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });

        Schema::table('fleet_fines', function (Blueprint $table) {
            $table->string('infringement_notice_path', 500)->nullable()->change();
        });
    }
};
