<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-module integration columns:
 *  - development activities & learning recommendations link to real LMS courses
 *    and the resulting enrolment, so a skill gap becomes a tracked enrolment.
 *  - goals gain a parent_goal_id for the OKR cascade/alignment tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pa_development_activities', function (Blueprint $table) {
            if (! Schema::hasColumn('pa_development_activities', 'lms_course_id')) {
                $table->foreignId('lms_course_id')->nullable()->after('activity_type')
                    ->constrained('lms_courses')->nullOnDelete();
            }
            if (! Schema::hasColumn('pa_development_activities', 'lms_enrollment_id')) {
                $table->foreignId('lms_enrollment_id')->nullable()->after('lms_course_id')
                    ->constrained('lms_enrollments')->nullOnDelete();
            }
        });

        Schema::table('pa_learning_recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('pa_learning_recommendations', 'lms_course_id')) {
                $table->foreignId('lms_course_id')->nullable()->after('skill_id')
                    ->constrained('lms_courses')->nullOnDelete();
            }
            if (! Schema::hasColumn('pa_learning_recommendations', 'lms_enrollment_id')) {
                $table->foreignId('lms_enrollment_id')->nullable()->after('lms_course_id')
                    ->constrained('lms_enrollments')->nullOnDelete();
            }
        });

        Schema::table('pa_goals', function (Blueprint $table) {
            if (! Schema::hasColumn('pa_goals', 'parent_goal_id')) {
                $table->foreignId('parent_goal_id')->nullable()->after('cycle_id')
                    ->constrained('pa_goals')->nullOnDelete();
            }
            if (! Schema::hasColumn('pa_goals', 'weight')) {
                $table->decimal('weight', 5, 2)->nullable()->after('current_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pa_development_activities', function (Blueprint $table) {
            if (Schema::hasColumn('pa_development_activities', 'lms_enrollment_id')) $table->dropConstrainedForeignId('lms_enrollment_id');
            if (Schema::hasColumn('pa_development_activities', 'lms_course_id')) $table->dropConstrainedForeignId('lms_course_id');
        });
        Schema::table('pa_learning_recommendations', function (Blueprint $table) {
            if (Schema::hasColumn('pa_learning_recommendations', 'lms_enrollment_id')) $table->dropConstrainedForeignId('lms_enrollment_id');
            if (Schema::hasColumn('pa_learning_recommendations', 'lms_course_id')) $table->dropConstrainedForeignId('lms_course_id');
        });
        Schema::table('pa_goals', function (Blueprint $table) {
            if (Schema::hasColumn('pa_goals', 'parent_goal_id')) $table->dropConstrainedForeignId('parent_goal_id');
            if (Schema::hasColumn('pa_goals', 'weight')) $table->dropColumn('weight');
        });
    }
};
