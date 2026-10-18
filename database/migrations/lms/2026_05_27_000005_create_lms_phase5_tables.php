<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Track actual time learner spent on each lesson and the whole enrollment
        Schema::table('lms_enrollments', function (Blueprint $table) {
            $table->unsignedInteger('time_spent_mins')->default(0)->after('progress_pct');
        });

        Schema::table('lms_lesson_completions', function (Blueprint $table) {
            $table->unsignedInteger('time_spent_mins')->default(0)->after('completed_at');
        });

        // Weighted understanding score per enrollment
        Schema::create('lms_understanding_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')
                  ->unique()
                  ->constrained('lms_enrollments')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('course_id')
                  ->constrained('lms_courses')
                  ->cascadeOnDelete();

            // Component scores (0-100 each)
            $table->decimal('lesson_completion_score', 5, 2)->default(0);
            $table->decimal('quiz_score',              5, 2)->default(0);
            $table->decimal('practical_score',         5, 2)->default(0);
            $table->decimal('time_score',              5, 2)->default(0);
            $table->decimal('manager_score',           5, 2)->default(0);
            $table->decimal('total_score',             5, 2)->default(0);
            $table->char('grade', 1)->default('F');   // A B C D F

            // Anomaly flags
            $table->boolean('guessing_detected')->default(false);
            $table->boolean('rushed_detected')->default(false);
            $table->boolean('low_engagement_detected')->default(false);

            // Certificate
            $table->boolean('is_certificate_blocked')->default(false);
            $table->text('certificate_blocked_reason')->nullable();

            // Refresher
            $table->boolean('refresher_recommended')->default(false);

            // Manager confirmation (10% weight)
            $table->boolean('manager_confirmed')->default(false);
            $table->foreignId('manager_confirmed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('manager_confirmed_at')->nullable();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });

        // Questions / topics a learner repeatedly fails
        Schema::create('lms_weak_learning_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('course_id')
                  ->nullable()
                  ->constrained('lms_courses')
                  ->nullOnDelete();
            $table->foreignId('questionnaire_id')
                  ->nullable()
                  ->constrained('lms_questionnaires')
                  ->nullOnDelete();
            $table->foreignId('question_id')
                  ->nullable()
                  ->constrained('lms_questions')
                  ->nullOnDelete();
            $table->string('topic')->nullable();        // friendly label / question text snippet
            $table->unsignedInteger('failure_count')->default(1);
            $table->decimal('average_score', 5, 2)->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('refresher_course_id')
                  ->nullable()
                  ->constrained('lms_courses')
                  ->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'question_id', 'course_id'], 'weak_area_unique');
        });

        // Aggregated gap report per enrollment
        Schema::create('lms_learning_gap_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')
                  ->unique()
                  ->constrained('lms_enrollments')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('course_id')
                  ->constrained('lms_courses')
                  ->cascadeOnDelete();
            $table->enum('report_type', ['individual', 'department', 'company'])->default('individual');
            $table->boolean('guessing_detected')->default(false);
            $table->boolean('rushed_detected')->default(false);
            $table->boolean('low_engagement_detected')->default(false);
            $table->decimal('total_understanding_score', 5, 2)->nullable();
            $table->decimal('pass_threshold', 5, 2)->nullable();
            $table->boolean('passed')->default(false);
            $table->unsignedInteger('weak_areas_count')->default(0);
            $table->jsonb('recommendations')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_learning_gap_reports');
        Schema::dropIfExists('lms_weak_learning_areas');
        Schema::dropIfExists('lms_understanding_scores');
        Schema::table('lms_lesson_completions', fn (Blueprint $t) => $t->dropColumn('time_spent_mins'));
        Schema::table('lms_enrollments',        fn (Blueprint $t) => $t->dropColumn('time_spent_mins'));
    }
};
