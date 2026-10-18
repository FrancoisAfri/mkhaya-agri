<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Path columns for two tables whose blob column is, confusingly, called `path`.
 *
 * `lms_course_materials.path` and `policy_documents.path` do not hold a path. They hold the whole
 * JSON envelope the shared upload trait returns — bytes, mime and original name — under a name that
 * reads as though the file were somewhere else. That is worth stating plainly, because anyone
 * grepping for storage columns skips them, and anyone reading the model assumes the file is on a
 * filesystem.
 *
 * The real pointer goes in `storage_path`, alongside every other table, and the misleading column
 * keeps its contents until those rows are pruned.
 */
return new class extends Migration
{
    private const TABLES = ['lms_course_materials', 'policy_documents'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'storage_path')) {
                continue;
            }

            Schema::table($table, fn (Blueprint $t) => $t->string('storage_path', 512)->nullable());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'storage_path')) {
                continue;
            }

            $stranded = \Illuminate\Support\Facades\DB::table($table)->whereNotNull('storage_path')->count();

            if ($stranded > 0) {
                throw new RuntimeException(
                    "Refusing to drop {$table}.storage_path: {$stranded} row(s) point at a stored file."
                );
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('storage_path'));
        }
    }
};
