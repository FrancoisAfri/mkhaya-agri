<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_gps_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('clocking_id')->nullable()->constrained('attendance_clockings')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->decimal('altitude_meters', 8, 2)->nullable();
            $table->string('address')->nullable()->comment('Reverse-geocoded address');
            $table->boolean('is_within_geofence')->default(false);
            $table->string('geofence_name')->nullable();
            $table->string('work_site')->nullable()->comment('Matched office/site name');
            $table->dateTime('captured_at');
            $table->timestamps();

            $table->index(['employee_id', 'captured_at']);
            $table->index(['is_within_geofence', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_gps_logs');
    }
};
