<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which entry of the built-in training library a course was installed from.
 *
 * Every farm gets its own copy of the agricultural training library when it signs up. The key is
 * what makes installing it idempotent — a re-run, a backfill or a library update finds the copy it
 * already made instead of adding a second one — and it is what tells a farm's own courses apart
 * from the ones that came with the system. Null for every course a farm creates itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_courses', function (Blueprint $table) {
            $table->string('library_key', 64)->nullable()->after('uuid');
            $table->unique(['company_id', 'library_key'], 'lms_courses_company_library_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('lms_courses', function (Blueprint $table) {
            $table->dropUnique('lms_courses_company_library_key_unique');
            $table->dropColumn('library_key');
        });
    }
};
