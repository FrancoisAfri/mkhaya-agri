<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master skills library
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable();    // technical|soft|leadership|compliance|operational
            $table->unsignedTinyInteger('max_level')->default(5);
            $table->jsonb('level_labels')->nullable();  // {1:"Beginner",…,5:"Expert"}
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['category', 'is_active']);
        });

        // Skills required per job title (hr_positions)
        Schema::create('job_title_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedTinyInteger('required_level')->default(3);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();

            $table->foreign('position_id')->references('id')->on('hr_positions')->cascadeOnDelete();
            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();
            $table->unique(['position_id', 'skill_id']);
        });

        // Skills a course teaches/grants
        Schema::create('course_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedTinyInteger('skill_level_gained')->default(1);
            $table->timestamps();

            $table->foreign('course_id')->references('id')->on('lms_courses')->cascadeOnDelete();
            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();
            $table->unique(['course_id', 'skill_id']);
        });

        // Skills assessed by individual questions
        Schema::create('question_skill_mapping', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('skill_id');
            $table->timestamps();

            $table->foreign('question_id')->references('id')->on('lms_questions')->cascadeOnDelete();
            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();
            $table->unique(['question_id', 'skill_id']);
        });

        // Per-employee skill levels
        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedTinyInteger('current_level')->default(1);
            $table->boolean('verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('source')->default('manual'); // manual|training|assessment|manager
            $table->timestamp('last_assessed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['user_id', 'skill_id']);
            $table->index(['user_id', 'source']);
        });

        // Identified skill gaps vs. role requirements
        Schema::create('employee_skill_gaps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedTinyInteger('current_level')->default(0);
            $table->unsignedTinyInteger('required_level');
            $table->unsignedTinyInteger('gap');                        // required - current
            $table->unsignedBigInteger('recommended_course_id')->nullable();
            $table->string('status')->default('open');                 // open|in_progress|closed
            $table->timestamp('identified_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();
            $table->foreign('position_id')->references('id')->on('hr_positions')->nullOnDelete();
            $table->foreign('recommended_course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->unique(['user_id', 'skill_id', 'position_id']);
            $table->index(['user_id', 'status']);
            $table->index(['position_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skill_gaps');
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('question_skill_mapping');
        Schema::dropIfExists('course_skills');
        Schema::dropIfExists('job_title_skills');
        Schema::dropIfExists('skills');
    }
};
