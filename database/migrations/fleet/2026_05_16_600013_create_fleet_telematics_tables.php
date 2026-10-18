<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Latest GPS position per vehicle (upserted on each sync)
        Schema::create('fleet_telematics_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('provider', 60);
            $table->string('provider_vehicle_id', 100)->nullable(); // external ID in provider system
            $table->decimal('latitude',  10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('heading',   6, 2)->nullable();
            $table->decimal('odometer_km', 12, 2)->nullable();
            $table->string('ignition_status', 20)->nullable(); // on | off | unknown
            $table->string('address', 500)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->unique(['vehicle_id', 'provider']); // one latest position per vehicle per provider
        });

        // Driving events (harsh braking, acceleration, speeding, geofence)
        Schema::create('fleet_telematics_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('provider', 60);
            $table->string('provider_event_id', 100)->nullable();
            $table->string('event_type', 60);  // harsh_braking | harsh_acceleration | speeding | geofence_enter | geofence_exit | idle
            $table->string('severity', 20)->nullable(); // low | medium | high
            $table->decimal('latitude',  10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('value', 10, 4)->nullable();   // e.g. g-force, km/h over limit
            $table->string('address', 500)->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('occurred_at');
            $table->index('event_type');
            $table->unique(['provider', 'provider_event_id']); // prevent duplicate import
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_telematics_events');
        Schema::dropIfExists('fleet_telematics_positions');
    }
};
