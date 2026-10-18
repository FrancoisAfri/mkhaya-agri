<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add payment_proof to the attachment_type enum (PostgreSQL)
        DB::statement("ALTER TABLE claim_attachments DROP CONSTRAINT IF EXISTS claim_attachments_attachment_type_check");
        DB::statement("ALTER TABLE claim_attachments ADD CONSTRAINT claim_attachments_attachment_type_check CHECK (attachment_type IN ('receipt','invoice','supporting_doc','other','payment_proof'))");

        Schema::table('claim_attachments', function (Blueprint $table) {
            // New DB-storage columns
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('mime', 100)->nullable()->after('mime_type');
            $table->string('type', 20)->nullable()->after('mime');    // 'image' | 'document'
            $table->longText('data')->nullable()->after('type');      // base64 binary

            // Legacy filesystem columns become nullable (kept for any existing rows)
            $table->string('filename', 255)->nullable()->change();
            $table->string('disk', 30)->nullable()->change();
            $table->string('path', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('claim_attachments', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'mime', 'type', 'data']);
        });

        DB::statement("ALTER TABLE claim_attachments DROP CONSTRAINT IF EXISTS claim_attachments_attachment_type_check");
        DB::statement("ALTER TABLE claim_attachments ADD CONSTRAINT claim_attachments_attachment_type_check CHECK (attachment_type IN ('receipt','invoice','supporting_doc','other'))");
    }
};
