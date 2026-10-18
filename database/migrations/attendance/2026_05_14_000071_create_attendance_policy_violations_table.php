<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policy_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('policy_rule_id')->constrained('attendance_policy_rules')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('detected_value');   // e.g. "5 lates in 30 days"
            $table->string('threshold_value');  // e.g. "threshold: 3"
            $table->string('status')->default('open'); // open|escalated|resolved|excepted
            $table->boolean('blocked')->default(false);
            $table->text('ai_enforcement_decision')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('policy_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policy_violations');
    }
};
