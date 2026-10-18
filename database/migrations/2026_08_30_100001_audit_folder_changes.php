<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditing what happens to folders, not only to documents.
 *
 * The trail recorded every act on a document and nothing at all about the structure holding them.
 * A folder could be renamed, moved, archived — and, most importantly, have its permissions changed —
 * with no record that anybody did it. Granting yourself access to Employee Files and reading it is
 * two events, and only the second was ever written down.
 *
 * The same table as document events, because "what happened here" is one question. document_id
 * becomes nullable so a folder event can stand on its own, and a check constraint keeps a row from
 * belonging to neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_events', function (Blueprint $table) {
            $table->unsignedBigInteger('folder_id')->nullable()->after('document_id');
            $table->index('folder_id');
        });

        DB::statement('ALTER TABLE document_events ALTER COLUMN document_id DROP NOT NULL');

        // An event about nothing is a row nobody can act on, and the only way to get one is a bug.
        DB::statement('ALTER TABLE document_events ADD CONSTRAINT document_events_subject
            CHECK (document_id IS NOT NULL OR folder_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_events DROP CONSTRAINT IF EXISTS document_events_subject');

        // Folder-only rows have no document to point at, so they go before the column is required
        // again. They are audit records, so this is stated rather than done quietly.
        DB::table('document_events')->whereNull('document_id')->delete();

        DB::statement('ALTER TABLE document_events ALTER COLUMN document_id SET NOT NULL');

        Schema::table('document_events', fn (Blueprint $table) => $table->dropColumn('folder_id'));
    }
};
