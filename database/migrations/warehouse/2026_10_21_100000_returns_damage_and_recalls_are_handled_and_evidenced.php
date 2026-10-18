<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-080 to WMS-083: customer returns, damage and wastage, quality control and recall.
 *
 * Most of this already exists in Manufacturing -- inspections with plans, results and dispositions
 * (MFG-009) and lot recalls with forward traceability (MFG-010) -- so the warehouse extends those
 * rather than keeping its own: inspections learn the bin they are about and three warehouse stages,
 * plans learn sampling rules, results learn defect codes, and a recall can cover a whole item or a
 * serial range, not only one lot and what it went into.
 *
 * New here: the customer return (an RMA and its lines), evidence that can be attached to anything
 * a loss is argued from, and the recovery of a loss from an insurer, a supplier or a carrier.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_customer_returns', 'wms_customer_return_lines', 'wms_evidence', 'wms_loss_recoveries', 'mfg_defect_codes'];

    public function up(): void
    {
        Schema::create('wms_customer_returns', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->foreignId('order_id')->nullable()->constrained('wms_orders')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('fin_invoices')->nullOnDelete();
            $t->string('reference', 120)->nullable(); // an authorised reference where there is no order or invoice
            $t->foreignId('customer_party_id')->nullable()->constrained('fin_parties')->nullOnDelete();
            $t->string('customer_name', 200)->nullable();
            $t->string('status', 16)->default('AUTHORISED'); // AUTHORISED|RECEIVED|INSPECTING|COMPLETED|CANCELLED
            $t->string('reason', 500)->nullable();
            $t->foreignId('credit_note_id')->nullable()->constrained('fin_invoices')->nullOnDelete();
            $t->decimal('credit_amount', 18, 2)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('received_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_rma_number_uniq');
        });

        Schema::create('wms_customer_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('return_id')->constrained('wms_customer_returns')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('order_line_id')->nullable()->constrained('wms_order_lines')->nullOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->jsonb('serials')->nullable();
            $t->decimal('qty_authorised', 18, 4);
            $t->decimal('qty_received', 18, 4)->default(0);
            $t->string('reason_code', 24)->nullable();
            $t->string('condition', 16)->nullable(); // UNOPENED|OPENED|DAMAGED|DEFECTIVE|WRONG_ITEM|EXPIRED
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('inspection_id')->nullable()->constrained('mfg_inspections')->nullOnDelete();
            $t->string('outcome', 20)->nullable(); // RESTOCKED|QUARANTINED|REPAIR|SUPPLIER_RETURN|DISPOSED|REJECTED
            $t->foreignId('movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestampsTz();
        });

        // Photographs, reports and letters behind a loss, a damaged return or a recall. Never deleted.
        Schema::create('wms_evidence', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('subject_type', 40); // ADJUSTMENT|RETURN_LINE|RECALL|RECOVERY|COUNT_LINE
            $t->unsignedBigInteger('subject_id');
            $t->string('kind', 24)->default('PHOTO');
            $t->string('path', 500);
            $t->string('original_name', 255);
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('caption', 255)->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id'], 'wms_evidence_subject_idx');
        });

        // Getting a loss back: an insurance claim, a supplier credit, a carrier claim.
        Schema::create('wms_loss_recoveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('adjustment_id')->constrained('wms_stock_adjustments')->cascadeOnDelete();
            $t->string('kind', 16); // INSURANCE|SUPPLIER|CARRIER|STAFF|OTHER
            $t->foreignId('party_id')->nullable()->constrained('fin_parties')->nullOnDelete();
            $t->string('claim_reference', 120)->nullable();
            $t->decimal('amount_claimed', 18, 2);
            $t->decimal('amount_recovered', 18, 2)->default(0);
            $t->string('status', 12)->default('OPEN'); // OPEN|SUBMITTED|SETTLED|DECLINED|WITHDRAWN
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('settled_at')->nullable();
            $t->timestampsTz();
        });

        Schema::create('mfg_defect_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('code', 24);
            $t->string('name', 120);
            $t->string('severity', 10)->default('MINOR'); // CRITICAL|MAJOR|MINOR
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->unique(['company_id', 'code'], 'mfg_defect_code_uniq');
        });

        Schema::table('mfg_inspection_results', fn (Blueprint $t) => $t->foreignId('defect_code_id')->nullable()->constrained('mfg_defect_codes')->nullOnDelete());
        Schema::table('mfg_inspections', function (Blueprint $t) {
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->string('source_type', 24)->nullable(); // WMS_RETURN|WMS_ORDER|WMS_STORAGE
            $t->unsignedBigInteger('source_id')->nullable();
        });
        Schema::table('mfg_inspection_plans', function (Blueprint $t) {
            $t->string('sampling_rule', 8)->default('FIXED'); // FIXED|PERCENT|SQRT|ALL
            $t->decimal('sample_pct', 6, 2)->nullable();
        });
        Schema::table('mfg_recalls', function (Blueprint $t) {
            $t->string('scope', 12)->default('LOT'); // LOT|ITEM|SERIALS
            $t->foreignId('stock_item_id')->nullable()->constrained('fin_stock_items')->nullOnDelete();
            $t->string('serial_from', 120)->nullable();
            $t->string('serial_to', 120)->nullable();
            $t->string('item_status_before', 16)->nullable();
        });
        DB::statement('ALTER TABLE mfg_recalls ALTER COLUMN origin_lot_id DROP NOT NULL');
        // 'CLASS_III' is nine characters; the column held eight, so a class III recall could never be saved.
        DB::statement('ALTER TABLE mfg_recalls ALTER COLUMN severity TYPE varchar(12)');
        DB::statement("ALTER TABLE mfg_recalls ADD CONSTRAINT mfg_recall_has_subject CHECK (origin_lot_id IS NOT NULL OR stock_item_id IS NOT NULL)");

        Schema::table('wms_reason_codes', function (Blueprint $t) {
            $t->boolean('requires_evidence')->default(false);
            $t->boolean('approve_high_risk')->default(true);
        });
        Schema::table('wms_item_profiles', fn (Blueprint $t) => $t->string('loss_risk', 8)->nullable()); // LOW|MEDIUM|HIGH

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TENANT_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
            DB::unprepared('CREATE TRIGGER wms_evidence_append_only BEFORE UPDATE OR DELETE ON wms_evidence '
                .'FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION wms_refuse_history_edit()');
        }
    }

    public function down(): void
    {
        Schema::table('wms_item_profiles', fn (Blueprint $t) => $t->dropColumn('loss_risk'));
        Schema::table('wms_reason_codes', fn (Blueprint $t) => $t->dropColumn(['requires_evidence', 'approve_high_risk']));
        DB::statement('ALTER TABLE mfg_recalls DROP CONSTRAINT IF EXISTS mfg_recall_has_subject');
        Schema::table('mfg_recalls', function (Blueprint $t) {
            $t->dropConstrainedForeignId('stock_item_id');
            $t->dropColumn(['scope', 'serial_from', 'serial_to', 'item_status_before']);
        });
        Schema::table('mfg_inspection_plans', fn (Blueprint $t) => $t->dropColumn(['sampling_rule', 'sample_pct']));
        Schema::table('mfg_inspections', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bin_id');
            $t->dropColumn(['source_type', 'source_id']);
        });
        Schema::table('mfg_inspection_results', fn (Blueprint $t) => $t->dropConstrainedForeignId('defect_code_id'));
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
