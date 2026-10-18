<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetDigitalTwinsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_digital_twins', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id')->unique();
            $table->string('condition', 20)->nullable();
            $table->unsignedInteger('current_location_id')->nullable();
            $table->float('operating_hours')->default(0);
            $table->float('odometer_reading')->default(0);
            $table->timestamp('last_maintenance_at')->nullable();
            $table->date('next_maintenance_due')->nullable();
            $table->float('health_score')->nullable()->comment('0-100, higher = healthier');
            $table->float('utilization_rate')->nullable()->comment('0-100 percentage');
            $table->json('sensor_data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('current_location_id')->references('id')->on('asset_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_digital_twins');
    }
}
