<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leave_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('leave_cycle_id')->nullable()->constrained('leave_cycles')->nullOnDelete();

            $table->decimal('override_hours', 8, 2);
            $table->string('reason', 500)->nullable();

            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();

            $table->boolean('active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'leave_type_id']);
            $table->index(['employee_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_overrides');
    }
};
