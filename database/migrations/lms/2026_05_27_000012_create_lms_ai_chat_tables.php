<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extends ai_chat_sessions with LMS-specific context
        Schema::create('lms_ai_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('context_type', ['course', 'lesson', 'policy', 'compliance', 'skills_gap', 'general'])->default('general');
            $table->unsignedBigInteger('context_id')->nullable();
            $table->enum('persona', ['employee', 'manager', 'hr_admin'])->default('employee');
            $table->string('title', 200)->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();

            $table->index(['user_id', 'context_type', 'context_id']);
        });

        // Generated artefacts (study guides, practice quizzes, summaries)
        Schema::create('lms_ai_artefacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->enum('artefact_type', ['summary', 'study_guide', 'practice_quiz', 'policy_explanation', 'compliance_report']);
            $table->string('context_type', 60)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('title', 200)->nullable();
            $table->longText('content');
            $table->jsonb('structured_data')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'artefact_type']);
            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_ai_artefacts');
        Schema::dropIfExists('lms_ai_sessions');
    }
};
