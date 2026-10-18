<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allow per-question option shuffling (separate from question-order randomisation)
        Schema::table('lms_questionnaires', function (Blueprint $table) {
            $table->boolean('randomise_options')->default(false)->after('randomise_questions');
        });

        // Final computed score per attempt — written once auto-grading is done,
        // updated again when manual marking is finalised.
        Schema::create('lms_assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')
                  ->unique()
                  ->constrained('lms_questionnaire_attempts')
                  ->cascadeOnDelete();
            $table->foreignId('questionnaire_id')
                  ->constrained('lms_questionnaires')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->integer('total_points')->default(0);
            $table->decimal('auto_earned_points',   8, 2)->default(0);
            $table->decimal('manual_earned_points', 8, 2)->default(0);
            $table->decimal('total_earned_points',  8, 2)->default(0);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();
        });

        // One row per response that requires human review.
        Schema::create('lms_manual_marking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')
                  ->constrained('lms_question_responses')
                  ->cascadeOnDelete();
            $table->foreignId('attempt_id')
                  ->constrained('lms_questionnaire_attempts')
                  ->cascadeOnDelete();
            $table->foreignId('question_id')
                  ->constrained('lms_questions')
                  ->cascadeOnDelete();
            $table->foreignId('questionnaire_id')
                  ->constrained('lms_questionnaires')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')          // learner
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('assigned_to')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->enum('status', ['pending', 'in_review', 'reviewed'])->default('pending');
            $table->integer('max_points')->default(0);
            $table->decimal('points_awarded', 8, 2)->nullable();
            $table->text('marker_notes')->nullable();
            $table->foreignId('marked_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_manual_marking');
        Schema::dropIfExists('lms_assessment_scores');
        Schema::table('lms_questionnaires', function (Blueprint $table) {
            $table->dropColumn('randomise_options');
        });
    }
};
