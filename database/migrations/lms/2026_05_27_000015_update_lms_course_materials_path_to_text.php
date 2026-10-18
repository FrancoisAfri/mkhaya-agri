<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_course_materials', function (Blueprint $table) {
            // Widen path to TEXT to hold base64 JSON blobs for database-stored documents.
            // Videos continue to use filesystem paths; documents use disk='database' with JSON.
            $table->text('path')->change();
        });
    }

    public function down(): void
    {
        Schema::table('lms_course_materials', function (Blueprint $table) {
            $table->string('path')->change();
        });
    }
};
