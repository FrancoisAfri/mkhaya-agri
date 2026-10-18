<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for each module's file rows to point once their bytes move to the shared disk.
 *
 * Additive and nullable on purpose: adding the column changes nothing on its own, so it can go out
 * well ahead of the migration that fills it. Nothing reads these until a module is switched over,
 * and a row with a null pointer still serves its bytes from the old column — which is what lets the
 * two worlds coexist while the switch happens module by module.
 */
return new class extends Migration
{
    /** Tables that hold uploaded documents and have nowhere to record a path yet. */
    private array $tables = [
        'tender_documents', 'tender_library_documents', 'tender_company_documents',
        'governance_board_packs', 'governance_action_evidence',
        'supplier_documents', 'supplier_invoices',
        'onboarding_documents', 'lms_poe_evidence', 'hd_attachments', 'survey_documents',
        'sheq_files', 'pm_document_blobs', 'pm_contractor_invoices',
        'procurement_requisition_attachments', 'purchase_request_attachments',
        'fleet_job_card_images', 'content_assets',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'storage_path')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->string('storage_path', 1000)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'storage_path')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('storage_path'));
            }
        }
    }
};
