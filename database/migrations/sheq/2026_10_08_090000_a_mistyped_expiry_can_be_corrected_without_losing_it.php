<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A contractor document can be set aside without being destroyed.
 *
 * `ContractorService::complianceFile()` picks, per document type, the row with the furthest expiry
 * date. That is deliberate and almost always right — a renewal has a later expiry than the
 * certificate it replaces, so attaching one unblocks the contractor without anybody deleting
 * anything.
 *
 * It has one consequence nobody could get out of. Somebody types 2030 where they meant 2026. The
 * contractor reads COMPLIANT, and attaching the *correct* certificate does not fix it: the typo
 * still has the furthest expiry, so it still wins. There was no way to correct the record. The
 * options available were to delete the wrong row — destroying compliance evidence, which rule 8
 * forbids and which an auditor asking "what were you holding in March" needs — or to leave a
 * contractor wrongly cleared for site access.
 *
 * So a row can be marked superseded: it stays exactly where it is, it stays readable, and the
 * compliance check stops counting it. Who set it aside and why are recorded on the row itself as
 * well as in the audit log, because the question an auditor asks about a set-aside certificate is
 * always "on whose say-so".
 *
 * Deliberately not a status column. A document is not in a workflow; it is either part of the live
 * file or it is history, and a nullable timestamp says that with no states to keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sheq_contractor_documents')) {
            return;
        }

        Schema::table('sheq_contractor_documents', function ($table) {
            if (! Schema::hasColumn('sheq_contractor_documents', 'superseded_at')) {
                $table->timestampTz('superseded_at')->nullable()
                    ->comment('Set aside from the live compliance file; the row is kept as evidence.');
            }

            if (! Schema::hasColumn('sheq_contractor_documents', 'superseded_by')) {
                $table->foreignId('superseded_by')->nullable()
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('sheq_contractor_documents', 'supersede_reason')) {
                $table->text('supersede_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sheq_contractor_documents')) {
            return;
        }

        Schema::table('sheq_contractor_documents', function ($table) {
            if (Schema::hasColumn('sheq_contractor_documents', 'superseded_by')) {
                $table->dropConstrainedForeignId('superseded_by');
            }

            foreach (['superseded_at', 'supersede_reason'] as $column) {
                if (Schema::hasColumn('sheq_contractor_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
