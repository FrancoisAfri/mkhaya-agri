<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('violation_id')->constrained('attendance_policy_violations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('policy_rule_id')->constrained('attendance_policy_rules')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('approved_at')->useCurrent();
            $table->date('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policy_exceptions');
    }
};
