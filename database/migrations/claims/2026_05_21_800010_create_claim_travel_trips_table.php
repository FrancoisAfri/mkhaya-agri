<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_travel_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->date('trip_date');
            $table->time('departure_time')->nullable();
            $table->string('origin', 500);
            $table->string('destination', 500);
            $table->decimal('distance_km', 10, 3)->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->integer('duration_in_traffic_minutes')->nullable();
            $table->boolean('is_peak_hour')->default(false);
            $table->string('traffic_condition', 20)->nullable(); // light, moderate, heavy
            $table->decimal('km_rate', 10, 4)->nullable();
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->decimal('peak_surcharge', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_travel_trips');
    }
};
