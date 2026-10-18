<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the legacy blob columns hold nothing.
 *
 * A module that has moved to the shared disk stops writing its blob column, and a NOT NULL
 * constraint turns that into a failed insert on the next upload. The constraint made sense when the
 * column was the only place a file could be — it is now the older of two, and about to be the empty
 * one.
 *
 * Found by a test rather than by reasoning: tender_library_documents.data was still NOT NULL after
 * that module was switched, so the change would have worked in every test that supplied a blob and
 * failed the first time a real person uploaded a document.
 *
 * Two are deliberately left alone, because their column is not a legacy anything:
 *
 *   notifications.data     Laravel's own JSON payload, always present
 *   sheq_file_chunks.data  a part of an in-flight upload, discarded once assembled
 */
return new class extends Migration
{
    private const TABLES = [
        'conference_files'             => 'data',
        'content_asset_variants'       => 'data',
        'disciplinary_appeal_evidence' => 'file_data',
        'hd_attachments'               => 'data',
        'pm_document_blobs'            => 'data',
        'survey_documents'             => 'file_data',
        'tender_library_documents'     => 'data',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Raw DDL rather than Schema::table()->nullable()->change(): a doctrine-driven change
            // rewrites the column type from its own idea of what the type is, and these columns
            // hold megabytes we have no reason to rewrite.
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Restoring the constraint fails while any row has moved to the disk, which is correct:
            // it would mean the rollback is happening after the migration it is meant to precede.
            $empty = DB::table($table)->whereNull($column)->count();

            if ($empty > 0) {
                throw new RuntimeException(
                    "Refusing to restore NOT NULL on {$table}.{$column}: {$empty} row(s) keep their "
                    . 'bytes on the shared disk. Move them back into the column first.'
                );
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
        }
    }
};
