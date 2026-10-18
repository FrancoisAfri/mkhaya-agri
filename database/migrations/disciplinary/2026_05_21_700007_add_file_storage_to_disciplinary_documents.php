<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Evidence: switch from filesystem path to DB binary storage ────────
        Schema::table('disciplinary_evidence', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('original_name', 255)->nullable()->after('file_name');
            $table->string('mime', 100)->nullable()->after('file_type');
            $table->longText('file_data')->nullable()->after('mime'); // base64 binary
            // Keep file_path nullable for existing rows; new rows use file_data
            $table->string('file_path', 500)->nullable()->change();
        });

        // ── AI evidence reports: add downloadable file storage ────────────────
        Schema::table('disciplinary_ai_evidence_reports', function (Blueprint $table) {
            $table->uuid('file_uuid')->nullable()->after('id');
            $table->string('original_name', 255)->nullable()->after('title');
            $table->string('mime', 100)->nullable()->after('original_name');
            $table->longText('file_data')->nullable()->after('mime');
        });

        // ── Investigation reports: add downloadable file storage ──────────────
        Schema::table('disciplinary_investigation_reports', function (Blueprint $table) {
            $table->uuid('file_uuid')->nullable()->after('id');
            $table->string('original_name', 255)->nullable()->after('title');
            $table->string('mime', 100)->nullable()->after('original_name');
            $table->longText('file_data')->nullable()->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('disciplinary_evidence', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'original_name', 'mime', 'file_data']);
        });
        Schema::table('disciplinary_ai_evidence_reports', function (Blueprint $table) {
            $table->dropColumn(['file_uuid', 'original_name', 'mime', 'file_data']);
        });
        Schema::table('disciplinary_investigation_reports', function (Blueprint $table) {
            $table->dropColumn(['file_uuid', 'original_name', 'mime', 'file_data']);
        });
    }
};
