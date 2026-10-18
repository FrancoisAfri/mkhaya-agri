<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->time('work_start_time')->default('08:00:00');
            $table->time('work_end_time')->default('17:00:00');
            $table->unsignedSmallInteger('grace_period_minutes')->default(15);
            $table->unsignedSmallInteger('overtime_threshold_minutes')->default(30);
            $table->unsignedSmallInteger('early_departure_threshold_minutes')->default(15);
            $table->boolean('late_policy_enabled')->default(true);
            $table->boolean('overtime_enabled')->default(true);
            $table->boolean('auto_clock_out_enabled')->default(false);
            $table->time('auto_clock_out_time')->nullable();
            $table->boolean('require_shift_assignment')->default(false);
            $table->boolean('allow_manual_entry')->default(true);
            $table->boolean('manager_approval_required')->default(false);
            $table->unsignedSmallInteger('working_days_per_week')->default(5);
            $table->unsignedSmallInteger('working_hours_per_day')->default(8);
            $table->string('timezone')->default('Africa/Johannesburg');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
