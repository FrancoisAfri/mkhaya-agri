<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Path columns for the file columns that a `data`/`file_data` search does not find.
 *
 * The first sweep looked for the obvious names and missed these, which are named after what they
 * hold rather than after being a blob — bank_file_data, disposal_document_data, a column simply
 * called `pdf`. Each is a real document sitting in a row.
 *
 * pm_contracts gets two, because a contract row holds two distinct files: the issued document and
 * the executed copy with signatures burned in. They are separate columns for a good reason — the
 * hash of each answers a different question — so they get separate paths rather than being
 * squeezed through one.
 *
 * Deliberately excluded:
 *
 *   jobs / failed_jobs / sessions / marketplace_* payload   queue and session state, not files
 *   esign_signers.signature_data                            a signature image, a few KB, rendered
 *   pm_contract_signatures.signature_data                   inline into a PDF as it is drawn
 *   procgov_bid_submission_documents.sealed_data            a sealed bid; the seal is procedural
 *                                                           and moving the bytes would need the
 *                                                           sealing rules revisited, not just the
 *                                                           storage
 */
return new class extends Migration
{
    /** table => the path columns to add. */
    private const COLUMNS = [
        'bureau_pay_runs'         => ['bank_file_path'],
        'fleet_vehicles'          => ['disposal_document_path'],
        'hd_service_reports'      => ['pdf_path'],
        'lms_learnership_logbook' => ['evidence_file_path'],
        'pm_contracts'            => ['document_path', 'signed_document_path'],
        'supplier_document_types' => ['template_path'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->string($column, 512)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // Dropping a pointer strands the file it referenced — it survives on the disk, but
                // nothing knows where. Refuse rather than do that quietly.
                $stranded = \Illuminate\Support\Facades\DB::table($table)->whereNotNull($column)->count();

                if ($stranded > 0) {
                    throw new RuntimeException(
                        "Refusing to drop {$table}.{$column}: {$stranded} row(s) point at a stored file."
                    );
                }

                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
