<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_poe_evidence', function (Blueprint $table) {
            $table->longText('file_data')->nullable()->after('url');
            $table->dropColumn('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('lms_poe_evidence', function (Blueprint $table) {
            $table->string('file_path', 500)->nullable()->after('url');
            $table->dropColumn('file_data');
        });
    }
};
