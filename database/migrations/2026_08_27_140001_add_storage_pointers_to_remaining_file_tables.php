<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the last of the file-owning tables somewhere to record a path.
 *
 * Each of these grew its own base64 column independently, which is how the platform ended up with
 * roughly twenty places a file could live and no single one to add encryption, scanning or
 * streaming to. The column here is what lets each module move onto the shared disk.
 *
 * Nullable and added alongside the existing blob rather than replacing it. A row can then hold
 * both, reads prefer the path, and the old column is dropped per module once nothing reads it —
 * which is what makes this safe to deploy before any data moves.
 *
 * Deliberately excluded, because their column is not a file despite the name:
 *
 *   notifications.data        Laravel's own JSON payload
 *   procurement_insights.data analytics output, not an upload
 *   sheq_file_chunks.data     staging for a chunked upload; the assembled file lands in sheq_files
 */
return new class extends Migration
{
    /** table => the blob column it already has, kept only as documentation of what is being replaced. */
    private const TABLES = [
        'bureau_leave_documents'             => 'file_data',
        'bureau_leave_report_runs'           => 'file_data',
        'bureau_payslips'                    => 'pdf_data',
        'claim_attachments'                  => 'data',
        'conference_files'                   => 'data',
        'content_asset_variants'             => 'data',
        'contracts'                          => 'file_data',
        'disciplinary_ai_evidence_reports'   => 'file_data',
        'disciplinary_appeal_evidence'       => 'file_data',
        'disciplinary_corrective_actions'    => 'file_data',
        'disciplinary_evidence'              => 'file_data',
        'disciplinary_investigation_reports' => 'file_data',
        'disciplinary_suspensions'           => 'file_data',
        'disciplinary_warnings'              => 'file_data',
        'fleet_job_card_documents'           => 'file_data',
        'pa_kpi_evidence'                    => 'data',
        'payslips'                           => 'pdf_data',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'storage_path')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->string('storage_path', 512)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'storage_path')) {
                continue;
            }

            /*
             * Dropping the pointer strands whatever it referenced. Once a module's blob column has
             * been pruned the path is the only copy, so this refuses rather than quietly making
             * files unreachable — the file itself survives on the disk, but nothing knows where.
             */
            $stranded = \Illuminate\Support\Facades\DB::table($table)->whereNotNull('storage_path')->count();

            if ($stranded > 0) {
                throw new RuntimeException(
                    "Refusing to drop {$table}.storage_path: {$stranded} row(s) point at a stored file. "
                    . 'Move those bytes back into the blob column first.'
                );
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('storage_path'));
        }
    }
};
