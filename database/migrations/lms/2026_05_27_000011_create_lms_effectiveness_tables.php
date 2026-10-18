<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Training cost records per course per period
        Schema::create('lms_training_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->decimal('cost_per_employee', 12, 2)->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('course_id');
        });

        // Computed effectiveness snapshots cached per course + period
        Schema::create('lms_effectiveness_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();
            $table->decimal('pass_rate', 5, 2)->nullable();
            $table->decimal('avg_score', 5, 2)->nullable();
            $table->decimal('avg_understanding_score', 5, 2)->nullable();
            $table->decimal('understanding_improvement', 8, 2)->nullable();
            $table->decimal('repeated_failure_rate', 5, 2)->nullable();
            $table->decimal('post_training_performance_delta', 8, 2)->nullable();
            $table->decimal('appraisal_delta', 8, 2)->nullable();
            $table->decimal('incident_delta', 8, 2)->nullable();
            $table->decimal('roi_pct', 10, 2)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->jsonb('snapshot_data')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'period_start', 'period_end']);
        });

        // Saved effectiveness reports
        Schema::create('lms_effectiveness_reports', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('report_type', 60)->default('course');
            $table->string('scope_type', 60)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('generated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_effectiveness_reports');
        Schema::dropIfExists('lms_effectiveness_snapshots');
        Schema::dropIfExists('lms_training_costs');
    }
};
