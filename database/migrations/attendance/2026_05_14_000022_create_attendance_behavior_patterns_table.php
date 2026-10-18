<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_behavior_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->string('pattern_type', 50)
                ->comment('late_days,absence_days,overtime_days,early_departure_days,work_type_preference,buddy_pair,shift_deviation');
            $table->string('period_type', 20)->default('monthly')->comment('weekly,monthly,quarterly');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data')->nullable()->comment('Pattern detail: day counts, frequencies, peer IDs');
            $table->text('insight')->nullable()->comment('AI-generated plain-language insight');
            $table->decimal('confidence', 5, 2)->nullable()->comment('0-100 pattern confidence');
            $table->timestamps();

            $table->index(['employee_id', 'pattern_type', 'period_start']);
            $table->index(['period_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_behavior_patterns');
    }
};
