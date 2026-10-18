<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_documents', function (Blueprint $table) {
            // Base64 JSON blob — same pattern as policy_documents.path
            $table->text('path')->nullable()->after('storage_path');

            // These are no longer needed for DB-storage mode
            $table->string('stored_filename', 255)->nullable()->change();
            $table->string('storage_path', 500)->nullable()->change();

            // Default to database storage
            $table->string('disk', 50)->default('database')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_documents', function (Blueprint $table) {
            $table->dropColumn('path');
            $table->string('stored_filename', 255)->nullable(false)->change();
            $table->string('storage_path', 500)->nullable(false)->change();
            $table->string('disk', 50)->default('private')->change();
        });
    }
};
