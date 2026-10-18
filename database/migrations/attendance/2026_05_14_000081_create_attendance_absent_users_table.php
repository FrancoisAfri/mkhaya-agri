<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_absent_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('email_sent')->default(false);
            $table->boolean('is_applied')->default(false);  // leave was applied
            $table->boolean('is_deducted')->default(false); // leave was auto-deducted
            $table->string('source')->default('ers');       // ers|manual
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'is_applied']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_absent_users');
    }
};
