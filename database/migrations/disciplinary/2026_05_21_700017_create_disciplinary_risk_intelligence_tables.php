<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_risk_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->enum('prediction_type', [
                'repeat_offence', 'escalation', 'dismissal_risk',
                'suspension_risk', 'policy_violation', 'behavioral_decline',
            ]);
            $table->decimal('probability', 5, 2);           // 0-100
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->date('prediction_window_end');           // prediction is valid until this date
            $table->text('reasoning');
            $table->json('contributing_factors')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->boolean('actioned')->default(false);
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->boolean('is_ai_generated')->default(true);
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'risk_level']);
            $table->index(['prediction_type', 'probability']);
            $table->index('prediction_window_end');
        });

        Schema::create('department_behavior_risk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('company_units');
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('total_cases')->default(0);
            $table->integer('active_cases')->default(0);
            $table->integer('dismissals')->default(0);
            $table->integer('suspensions')->default(0);
            $table->integer('warnings_issued')->default(0);
            $table->integer('repeat_offenders')->default(0);
            $table->decimal('avg_case_duration_days', 6, 1)->default(0);
            $table->decimal('risk_score', 5, 2)->default(0);        // 0-100
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->enum('trend', ['improving', 'stable', 'worsening'])->default('stable');
            $table->json('top_offence_categories')->nullable();
            $table->text('ai_narrative')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'period_start', 'period_end']);
            $table->index(['risk_level', 'period_end']);
        });

        Schema::create('disciplinary_heatmaps', function (Blueprint $table) {
            $table->id();
            $table->enum('heatmap_type', [
                'department_risk', 'offence_category', 'monthly_trend',
                'employee_risk', 'sanction_distribution',
            ]);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data_points');        // [{label, value, risk_level, meta}]
            $table->json('legend')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['heatmap_type', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_heatmaps');
        Schema::dropIfExists('department_behavior_risk');
        Schema::dropIfExists('disciplinary_risk_predictions');
    }
};
