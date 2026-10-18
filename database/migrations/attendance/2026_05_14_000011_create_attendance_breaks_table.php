<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->foreignId('clock_in_clocking_id')->nullable()->constrained('attendance_clockings')->nullOnDelete();
            $table->foreignId('clock_out_clocking_id')->nullable()->constrained('attendance_clockings')->nullOnDelete();
            $table->string('break_type', 30)->default('other')->comment('lunch,tea,personal,other');
            $table->dateTime('break_start');
            $table->dateTime('break_end')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->boolean('is_open')->default(true)->comment('Break not yet ended');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'break_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_breaks');
    }
};
