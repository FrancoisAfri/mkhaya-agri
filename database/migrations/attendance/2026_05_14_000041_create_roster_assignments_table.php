<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_schedule_id')->constrained('roster_schedules')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('attendance_shifts')->nullOnDelete();
            $table->date('assigned_date');
            $table->string('status', 20)->default('scheduled')->comment('scheduled,confirmed,swapped,cancelled,absent');
            $table->boolean('is_ai_suggested')->default(false);
            $table->boolean('is_rest_day')->default(false);
            $table->string('swap_with_employee')->nullable();
            $table->text('swap_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['roster_schedule_id', 'employee_id', 'assigned_date']);
            $table->index(['employee_id', 'assigned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_assignments');
    }
};
