<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `source_id` was sized for a foreign key — an employee number, a customer id. The local
 * DocumentStore also uses it to hold a storage path, and those are longer than 64 characters as
 * soon as a project uuid and a file uuid are both in them, so every local attachment upload failed
 * on insert.
 *
 * 255 with the path capped on the way in (LocalDocumentStore), rather than text: the column is
 * indexed for lookups by source, and an unbounded key is a slow index waiting to happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pm_external_refs ALTER COLUMN source_id TYPE varchar(255)');

            return;
        }

        Schema::table('pm_external_refs', function ($t) {
            $t->string('source_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pm_external_refs ALTER COLUMN source_id TYPE varchar(64)');

            return;
        }

        Schema::table('pm_external_refs', function ($t) {
            $t->string('source_id', 64)->nullable()->change();
        });
    }
};
