<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->default('tabler-books');
            $table->string('color')->default('primary');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lms_courses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('lms_categories')->nullOnDelete();
            $table->string('cover_image')->nullable();
            $table->enum('type', ['video', 'document', 'quiz', 'scorm', 'external'])->default('document');
            $table->integer('duration_mins')->default(0);
            $table->tinyInteger('pass_score')->default(70);
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'is_mandatory']);
        });

        Schema::create('lms_course_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->enum('type', ['video', 'document', 'text', 'link', 'quiz']);
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('url')->nullable();
            $table->string('file_path')->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('duration_mins')->default(0);
            $table->timestamps();
        });

        Schema::create('lms_learning_paths', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lms_learning_path_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('path_id')->constrained('lms_learning_paths')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['path_id', 'course_id']);
        });

        Schema::create('lms_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->enum('status', ['enrolled', 'in_progress', 'completed', 'failed'])->default('enrolled');
            $table->tinyInteger('progress_pct')->default(0);
            $table->tinyInteger('score')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index('status');
            $table->index('user_id');
        });

        Schema::create('lms_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->text('question');
            $table->enum('type', ['mcq', 'true_false', 'multi_select'])->default('mcq');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lms_quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('lms_quiz_questions')->cascadeOnDelete();
            $table->string('option_text');
            $table->boolean('is_correct')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lms_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
            $table->tinyInteger('score');
            $table->boolean('passed');
            $table->jsonb('answers');
            $table->timestamp('attempted_at');
            $table->timestamps();
        });

        Schema::create('lms_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('enrollment_id')->unique()->constrained('lms_enrollments')->cascadeOnDelete();
            $table->string('certificate_number')->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_certificates');
        Schema::dropIfExists('lms_quiz_attempts');
        Schema::dropIfExists('lms_quiz_options');
        Schema::dropIfExists('lms_quiz_questions');
        Schema::dropIfExists('lms_enrollments');
        Schema::dropIfExists('lms_learning_path_courses');
        Schema::dropIfExists('lms_learning_paths');
        Schema::dropIfExists('lms_course_contents');
        Schema::dropIfExists('lms_courses');
        Schema::dropIfExists('lms_categories');
    }
};
