<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->decimal('tank_size', 8, 1)->nullable()->after('fuel_type');
            $table->string('vehicle_cell_number', 30)->nullable()->after('tank_size');
            $table->string('tracking_cell_number', 30)->nullable()->after('vehicle_cell_number');
            $table->string('owner_name', 100)->nullable()->after('tracking_cell_number');
            $table->string('title_holder_name', 100)->nullable()->after('owner_name');
            $table->text('extras')->nullable()->after('title_holder_name');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->dropColumn(['tank_size', 'vehicle_cell_number', 'tracking_cell_number', 'owner_name', 'title_holder_name', 'extras']);
        });
    }
};
