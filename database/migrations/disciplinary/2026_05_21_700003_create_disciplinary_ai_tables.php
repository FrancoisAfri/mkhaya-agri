<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Per-employee misconduct risk scores ───────────────────────────────
        Schema::create('disciplinary_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->decimal('risk_score', 5, 2)->default(0);          // 0–100
            $table->string('risk_level', 20)->default('low');          // low, medium, high, critical
            $table->json('risk_factors')->nullable();                   // [{factor, weight, detail}]
            $table->unsignedSmallInteger('total_cases')->default(0);
            $table->unsignedSmallInteger('open_cases')->default(0);
            $table->unsignedSmallInteger('closed_cases')->default(0);
            $table->unsignedSmallInteger('repeat_offense_count')->default(0);
            $table->unsignedSmallInteger('gross_misconduct_count')->default(0);
            $table->unsignedSmallInteger('serious_misconduct_count')->default(0);
            $table->unsignedSmallInteger('minor_misconduct_count')->default(0);
            $table->boolean('is_repeat_offender')->default(false);
            $table->date('last_case_date')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->unsignedBigInteger('analyzed_by')->nullable();
            $table->timestamps();

            $table->index('risk_level');
            $table->index('risk_score');
        });

        // ── Detected behavioral patterns ──────────────────────────────────────
        Schema::create('disciplinary_behavior_patterns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            // repeat_offender | attendance_misconduct | payroll_fraud_risk | policy_violation | team_behavioral
            $table->string('pattern_type', 60);
            $table->string('pattern_label', 120);
            $table->text('pattern_description');
            $table->string('severity', 20)->default('low');            // low, medium, high
            $table->json('supporting_data')->nullable();
            $table->date('detected_at');
            $table->boolean('acknowledged')->default(false);
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('pattern_type');
            $table->index('detected_at');
        });

        // ── AI-generated summaries (case, employee, or department) ────────────
        Schema::create('disciplinary_ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 60);   // case | employee | department
            $table->unsignedBigInteger('subject_id');
            // case_summary | sanction_recommendation | misconduct_analysis | history_analysis
            $table->string('summary_type', 60);
            $table->text('summary');
            $table->text('recommendation')->nullable();
            $table->json('ai_metadata')->nullable();                    // tokens, prompt, etc.
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 80)->nullable();
            $table->boolean('acknowledged')->default(false);
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('summary_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_ai_summaries');
        Schema::dropIfExists('disciplinary_behavior_patterns');
        Schema::dropIfExists('disciplinary_risk_scores');
    }
};
