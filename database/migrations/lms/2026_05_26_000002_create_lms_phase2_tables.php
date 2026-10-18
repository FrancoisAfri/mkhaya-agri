<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add expiry support to courses
        Schema::table('lms_courses', function (Blueprint $table) {
            $table->smallInteger('expires_after_days')->nullable()->after('pass_score')
                ->comment('Days after completion before certificate/access expires. null = never.');
        });

        // Modules (chapters) within a course
        Schema::create('lms_course_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });

        // Lessons within a module
        Schema::create('lms_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('lms_course_modules')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title');
            $table->enum('type', ['video', 'pdf', 'document', 'presentation', 'text', 'link'])
                  ->default('text');
            $table->longText('content_body')->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->integer('duration_mins')->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_preview')->default(false)
                ->comment('Visible to non-enrolled users as a teaser');
            $table->timestamps();

            $table->index(['module_id', 'sort_order']);
            $table->index('course_id');
        });

        // Uploaded files attached to a lesson (videos, PDFs, presentations, etc.)
        Schema::create('lms_course_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->nullOnDelete();
            $table->string('title');
            $table->enum('type', ['video', 'pdf', 'document', 'presentation', 'other'])
                  ->default('document');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('file_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->integer('sort_order')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['course_id', 'lesson_id']);
        });

        // Courses a user must complete before enrolling in this course
        Schema::create('lms_course_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('prerequisite_course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'prerequisite_course_id']);
            $table->index('course_id');
        });

        // Individual lesson completion records per enrollment
        Schema::create('lms_lesson_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['enrollment_id', 'lesson_id']);
            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_lesson_completions');
        Schema::dropIfExists('lms_course_prerequisites');
        Schema::dropIfExists('lms_course_materials');
        Schema::dropIfExists('lms_lessons');
        Schema::dropIfExists('lms_course_modules');
        Schema::table('lms_courses', function (Blueprint $table) {
            $table->dropColumn('expires_after_days');
        });
    }
};
