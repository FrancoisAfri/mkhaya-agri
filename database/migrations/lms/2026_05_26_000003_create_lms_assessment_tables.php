<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reusable question libraries
        Schema::create('lms_question_banks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Assessment / questionnaire definitions
        Schema::create('lms_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->enum('type', [
                'questionnaire',
                'pre_assessment',
                'post_assessment',
                'knowledge_check',
                'final_test',
                'practical_assessment',
                'self_assessment',
                'manager_observation',
                'feedback',
            ])->default('questionnaire');
            $table->smallInteger('time_limit_mins')->nullable()
                ->comment('null = no time limit');
            $table->tinyInteger('pass_score')->nullable()
                ->comment('null = no pass/fail threshold (feedback/observation)');
            $table->boolean('allow_retake')->default(true);
            $table->tinyInteger('max_retakes')->nullable()
                ->comment('null = unlimited');
            $table->boolean('randomise_questions')->default(false);
            $table->boolean('show_answers_after')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Questions — owned by a questionnaire OR a bank (one must be set)
        Schema::create('lms_questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('questionnaire_id')
                  ->nullable()->constrained('lms_questionnaires')->cascadeOnDelete();
            $table->foreignId('bank_id')
                  ->nullable()->constrained('lms_question_banks')->cascadeOnDelete();
            $table->foreignId('source_question_id')
                  ->nullable()->constrained('lms_questions')->nullOnDelete()
                  ->comment('Set when copied from a bank into a questionnaire');
            $table->enum('type', [
                'mcq',
                'multi_select',
                'true_false',
                'short_answer',
                'long_answer',
                'file_upload',
                'practical',
                'rating_scale',
                'yes_no',
                'scenario',
                'policy_ack',
            ])->default('mcq');
            $table->text('question_text');
            $table->text('context_text')->nullable()
                ->comment('Scenario background / policy excerpt');
            $table->jsonb('correct_answer')->nullable()
                ->comment('Auto-gradable: expected option IDs or boolean');
            $table->text('explanation')->nullable()
                ->comment('Shown to learner after answering (if show_answers_after)');
            $table->tinyInteger('points')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['questionnaire_id', 'sort_order']);
            $table->index('bank_id');
        });

        // Answer options for mcq / multi_select / true_false / yes_no / rating_scale
        Schema::create('lms_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
            $table->string('option_text');
            $table->boolean('is_correct')->default(false);
            $table->smallInteger('rating_value')->nullable()
                ->comment('For rating_scale: numeric value this option represents');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'sort_order']);
        });

        // Links a questionnaire to a course or lesson
        Schema::create('lms_questionnaire_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained('lms_questionnaires')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->nullOnDelete();
            $table->enum('assignment_type', [
                'pre_course',
                'post_course',
                'lesson_check',
                'standalone',
            ])->default('standalone');
            $table->boolean('is_required')->default(false);
            $table->smallInteger('due_days_after_enrol')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'assignment_type']);
            $table->index(['lesson_id', 'assignment_type']);
        });

        // A single user attempt at a questionnaire
        Schema::create('lms_questionnaire_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('questionnaire_id')->constrained('lms_questionnaires')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('lms_questionnaire_assignments')->nullOnDelete();
            $table->enum('status', [
                'in_progress',
                'completed',
                'passed',
                'failed',
                'pending_review',
            ])->default('in_progress');
            $table->decimal('score', 5, 2)->nullable()
                ->comment('0-100 percentage; null until graded');
            $table->tinyInteger('attempt_number')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['questionnaire_id', 'user_id']);
            $table->index('status');
        });

        // Individual question responses within an attempt
        Schema::create('lms_question_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('lms_questionnaire_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
            $table->text('response_text')->nullable()
                ->comment('Short/long answer text');
            $table->jsonb('response_json')->nullable()
                ->comment('Selected option IDs, rating value, yes/no boolean, etc.');
            $table->string('file_path')->nullable();
            $table->string('file_disk')->nullable();
            $table->string('file_name')->nullable();
            $table->boolean('is_correct')->nullable()
                ->comment('null for open-ended / pending review');
            $table->decimal('points_awarded', 4, 2)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
            $table->index('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_question_responses');
        Schema::dropIfExists('lms_questionnaire_attempts');
        Schema::dropIfExists('lms_questionnaire_assignments');
        Schema::dropIfExists('lms_question_options');
        Schema::dropIfExists('lms_questions');
        Schema::dropIfExists('lms_questionnaires');
        Schema::dropIfExists('lms_question_banks');
    }
};
