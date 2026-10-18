<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-009 -- quality management.
 *
 * "Quality must be built into production, rather than handled only after products are
 * manufactured." So an inspection is not a form filled in afterwards: it is opened by the event
 * that needs it (a receipt into quarantine, an inspection point on the routing, output landing in
 * quarantine), the stock it is about is held in a status nothing can issue, and only an authorised
 * disposition moves it on.
 *
 * Each result keeps a copy of the limits it was judged against. A plan edited next month cannot
 * turn yesterday's pass into a fail, and the inspection report still shows what was asked.
 */
return new class extends Migration
{
    private const TABLES = [
        'mfg_inspection_plans', 'mfg_inspection_characteristics', 'mfg_inspections',
        'mfg_inspection_results', 'mfg_nonconformances', 'mfg_ncr_actions',
    ];

    public function up(): void
    {
        Schema::create('mfg_inspection_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('name');
            $table->string('stage', 16); // INCOMING|IN_PROCESS|FINAL

            // What it applies to. Incoming plans name the purchased material (and optionally its
            // supplier); in-process and final plans name the product, and an in-process plan may
            // be pinned to one operation of a routing.
            $table->foreignId('stock_item_id')->nullable()->constrained('fin_stock_items')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('mfg_items')->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreignId('routing_operation_id')->nullable()->constrained('mfg_routing_operations')->nullOnDelete();

            $table->unsignedInteger('sample_size')->nullable();
            // "Require inspections at specified production quantities or intervals."
            $table->decimal('every_qty', 18, 6)->nullable();
            // A failure stops production until somebody authorised decides what happens.
            $table->boolean('blocks_production')->default(true);
            $table->boolean('requires_certificate')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'mfg_qplan_code_uq');
            $table->index(['company_id', 'stage', 'is_active'], 'mfg_qplan_stage_idx');
        });

        Schema::create('mfg_inspection_characteristics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('mfg_inspection_plans')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(10);
            $table->string('name');
            $table->string('kind', 12)->default('MEASURED'); // MEASURED|ATTRIBUTE
            $table->string('uom', 24)->nullable();
            $table->decimal('target', 18, 6)->nullable();
            $table->decimal('lower_limit', 18, 6)->nullable();
            $table->decimal('upper_limit', 18, 6)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->string('method')->nullable();
            $table->timestamps();
        });

        Schema::create('mfg_nonconformances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 48);
            $table->string('source', 16); // INCOMING|IN_PROCESS|FINAL|CUSTOMER|INTERNAL
            $table->unsignedBigInteger('inspection_id')->nullable(); // FK added below (circular)

            $table->foreignId('stock_item_id')->nullable()->constrained('fin_stock_items')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $table->foreignId('production_order_id')->nullable()->constrained('mfg_production_orders')->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();

            // A code, so repeat defects can be counted; prose cannot be.
            $table->string('defect_code', 64)->nullable();
            $table->text('description');
            $table->decimal('qty_affected', 18, 6)->nullable();
            $table->string('severity', 10)->default('MINOR'); // MINOR|MAJOR|CRITICAL
            $table->string('status', 16)->default('OPEN');    // OPEN|INVESTIGATING|ACTION|VERIFICATION|CLOSED|VOID

            $table->text('containment')->nullable();
            $table->text('root_cause')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();

            $table->string('effectiveness', 16)->nullable(); // EFFECTIVE|NOT_EFFECTIVE
            $table->text('effectiveness_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('void_reason')->nullable();

            // Where the tenant runs SHEQ, the same non-conformance in its register.
            $table->unsignedBigInteger('sheq_ncr_id')->nullable();

            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'mfg_ncr_number_uq');
            $table->index(['company_id', 'status'], 'mfg_ncr_status_idx');
            $table->index(['company_id', 'defect_code'], 'mfg_ncr_defect_idx');
        });

        Schema::create('mfg_ncr_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('ncr_id')->constrained('mfg_nonconformances')->cascadeOnDelete();
            $table->string('type', 12); // CONTAINMENT|CORRECTIVE|PREVENTIVE
            $table->text('description');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 8)->default('OPEN'); // OPEN|DONE
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('mfg_inspections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 48);
            $table->string('stage', 16);
            $table->foreignId('plan_id')->nullable()->constrained('mfg_inspection_plans')->nullOnDelete();

            // What is being inspected, and where it is held while that happens.
            $table->foreignId('stock_item_id')->nullable()->constrained('fin_stock_items');
            $table->foreignId('lot_id')->nullable()->constrained('fin_stock_lots');
            $table->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses');
            $table->decimal('qty', 18, 6)->default(0);
            $table->string('held_status', 12)->nullable(); // QUARANTINE|ON_HOLD -- what disposition releases from
            $table->unsignedInteger('sample_size')->nullable();

            $table->foreignId('production_order_id')->nullable()->constrained('mfg_production_orders')->nullOnDelete();
            $table->foreignId('order_operation_id')->nullable()->constrained('mfg_production_order_operations')->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('receipt_reference')->nullable();

            $table->string('result', 8)->default('PENDING'); // PENDING|PASSED|FAILED
            $table->string('status', 24)->default('OPEN');   // OPEN|AWAITING_DISPOSITION|CLOSED
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('inspected_at')->nullable();

            $table->string('disposition', 24)->nullable(); // ACCEPT|CONDITIONAL_ACCEPT|REJECT|SCRAP|RETURN_TO_SUPPLIER|REWORK
            $table->text('disposition_reason')->nullable();
            $table->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('disposed_at')->nullable();

            $table->foreignId('ncr_id')->nullable()->constrained('mfg_nonconformances')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'mfg_insp_number_uq');
            $table->index(['company_id', 'status', 'stage'], 'mfg_insp_queue_idx');
            $table->index(['production_order_id'], 'mfg_insp_order_idx');
            $table->index(['lot_id'], 'mfg_insp_lot_idx');
        });

        Schema::table('mfg_nonconformances', function (Blueprint $table) {
            $table->foreign('inspection_id')->references('id')->on('mfg_inspections')->nullOnDelete();
        });

        Schema::create('mfg_inspection_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('inspection_id')->constrained('mfg_inspections')->cascadeOnDelete();
            $table->foreignId('characteristic_id')->nullable()->constrained('mfg_inspection_characteristics')->nullOnDelete();
            $table->unsignedInteger('sample_no')->default(1);

            // The question as it was asked: copied from the plan when the result was recorded.
            $table->string('name');
            $table->string('kind', 12);
            $table->string('uom', 24)->nullable();
            $table->decimal('target', 18, 6)->nullable();
            $table->decimal('lower_limit', 18, 6)->nullable();
            $table->decimal('upper_limit', 18, 6)->nullable();
            $table->boolean('is_critical')->default(false);

            $table->decimal('measured_value', 18, 6)->nullable();
            $table->boolean('passed');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recorded_at');
            $table->timestamps();
        });

        $this->applyTenantRls(self::TABLES);
        $this->guardDisposedInspections();
    }

    /**
     * Once an inspection has been disposed of, what was measured is evidence. The results cannot be
     * edited, added to or removed; a re-inspection is a new inspection.
     */
    private function guardDisposedInspections(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mfg_refuse_closed_inspection_result() RETURNS trigger AS $$
            DECLARE parent_status text;
            BEGIN
                SELECT status INTO parent_status FROM mfg_inspections WHERE id = COALESCE(NEW.inspection_id, OLD.inspection_id);
                IF parent_status = 'CLOSED' AND pg_trigger_depth() = 1 THEN
                    RAISE EXCEPTION 'The inspection is closed; its results are evidence and cannot be changed';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$ LANGUAGE plpgsql;

            CREATE TRIGGER mfg_insp_result_closed BEFORE INSERT OR UPDATE OR DELETE ON mfg_inspection_results
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_closed_inspection_result();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_insp_result_closed ON mfg_inspection_results');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_closed_inspection_result()');
        }

        Schema::table('mfg_nonconformances', fn (Blueprint $t) => $t->dropForeign(['inspection_id']));

        foreach (['mfg_inspection_results', 'mfg_inspections', 'mfg_ncr_actions', 'mfg_nonconformances',
            'mfg_inspection_characteristics', 'mfg_inspection_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
