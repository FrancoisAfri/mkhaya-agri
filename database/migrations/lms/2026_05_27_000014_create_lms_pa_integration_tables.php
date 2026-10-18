<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PA-driven training recommendations (KPI gaps → LMS courses)
        Schema::create('lms_pa_training_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
            $table->string('gap_area');                         // kpi title or skill name
            $table->string('kpi_title')->nullable();
            $table->decimal('score_pct', 5, 2)->nullable();     // the low score that triggered this
            $table->text('recommendation_reason')->nullable();
            // pending | accepted | enrolled | completed | dismissed
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');      // low | medium | high | critical
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'cycle_id']);
            $table->index(['employee_id', 'status']);
        });

        // LMS-side development plans linked to PA development plans
        Schema::create('lms_development_training_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pa_plan_id')->nullable();    // pa_development_plans.id
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('manager_id')->constrained('users');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->string('title');
            // draft | active | completed | paused
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('overall_progress')->default(0);
            $table->jsonb('lms_activities')->nullable();             // [{course_id, enrollment_id, title, status, progress_pct, understanding_score}]
            $table->text('notes')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['employee_id', 'cycle_id']);
            $table->index('pa_plan_id');
        });

        // Computed promotion readiness from LMS training data
        Schema::create('lms_promotion_readiness', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->decimal('overall_training_score', 5, 2)->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();
            $table->decimal('pass_rate', 5, 2)->nullable();
            $table->decimal('understanding_avg', 5, 2)->nullable();
            $table->decimal('development_completion_pct', 5, 2)->nullable();
            $table->boolean('is_promotion_ready')->default(false);
            $table->text('readiness_notes')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'cycle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_promotion_readiness');
        Schema::dropIfExists('lms_development_training_plans');
        Schema::dropIfExists('lms_pa_training_recommendations');
    }
};
