<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI recommendations per employee
        Schema::create('lms_ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('recommendation_type'); // job_title|performance_gap|failed_topics|career_path|policy_requirement|refresher
            $table->text('reason');
            $table->unsignedTinyInteger('priority')->default(3); // 1 (highest) – 5
            $table->jsonb('ai_context')->nullable();
            $table->jsonb('ai_response')->nullable();
            $table->string('status')->default('pending'); // pending|accepted|dismissed
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'recommendation_type']);
        });

        // Employee learning plans (AI-generated or manual)
        Schema::create('lms_learning_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('plan_type')->default('learning_plan'); // learning_plan|revision_plan
            $table->string('status')->default('active'); // draft|active|completed|archived
            $table->boolean('ai_generated')->default(false);
            $table->jsonb('ai_context')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'plan_type']);
        });

        // Items within a learning plan
        Schema::create('lms_learning_plan_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('learning_plan_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('questionnaire_id')->nullable();
            $table->string('item_type'); // course|assessment|revision|reading|practice
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('due_date')->nullable();
            $table->string('status')->default('pending'); // pending|in_progress|completed|skipped
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('learning_plan_id')->references('id')->on('lms_learning_plans')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->foreign('questionnaire_id')->references('id')->on('lms_questionnaires')->nullOnDelete();

            $table->index(['learning_plan_id', 'status']);
        });

        // Personalised revision plans tied to a course/enrollment
        Schema::create('lms_revision_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->jsonb('topic_areas')->nullable();
            $table->jsonb('weak_areas')->nullable();
            $table->jsonb('ai_suggestions')->nullable();
            $table->decimal('estimated_hours', 5, 1)->nullable();
            $table->string('status')->default('active'); // pending|active|completed
            $table->timestamp('generated_at')->useCurrent();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('lms_enrollments')->nullOnDelete();
            $table->foreign('course_id')->references('id')->on('lms_courses')->cascadeOnDelete();
            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_revision_plans');
        Schema::dropIfExists('lms_learning_plan_items');
        Schema::dropIfExists('lms_learning_plans');
        Schema::dropIfExists('lms_ai_recommendations');
    }
};
