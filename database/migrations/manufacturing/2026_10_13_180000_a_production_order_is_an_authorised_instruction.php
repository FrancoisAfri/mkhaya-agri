<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-006 -- production order management.
 *
 * "A production order represents an authorised instruction to manufacture a defined quantity of a
 *  product." Everything here follows from "authorised" and "defined": the order is pinned to one
 * BOM revision and one routing revision when it is created, its components and operations are
 * copied from them so later engineering changes cannot reach it, every change of state is recorded
 * with who and why, and once closed it is a record the database will not let anybody rewrite --
 * corrections go through audited adjustments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfg_production_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 48);

            $table->foreignId('factory_id')->constrained('mfg_factories');
            $table->foreignId('warehouse_id')->constrained('fin_warehouses');        // where output lands
            $table->foreignId('component_warehouse_id')->nullable()->constrained('fin_warehouses'); // where material comes from

            $table->foreignId('item_id')->constrained('mfg_items');
            $table->foreignId('bom_id')->constrained('mfg_boms');
            $table->foreignId('routing_id')->nullable()->constrained('mfg_routings');

            $table->decimal('planned_qty', 18, 6);
            $table->date('due_date');
            $table->unsignedTinyInteger('priority')->default(3); // 1 urgent .. 5 low

            $table->string('status', 20)->default('DRAFT');
            $table->string('status_before_hold', 20)->nullable();

            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();

            // What it is for: a customer order line, a replenishment, a plan recommendation.
            $table->string('demand_type', 32)->nullable();   // CUSTOMER_ORDER|REPLENISHMENT|FORECAST|MANUAL|INTER_FACTORY
            $table->unsignedBigInteger('demand_id')->nullable();
            $table->string('demand_reference')->nullable();

            $table->timestampTz('planned_start')->nullable();
            $table->timestampTz('planned_finish')->nullable();

            // Estimates, worked out when the order is planned.
            $table->decimal('est_material_cost', 18, 2)->default(0);
            $table->decimal('est_labour_cost', 18, 2)->default(0);
            $table->decimal('est_machine_cost', 18, 2)->default(0);
            $table->decimal('est_overhead_cost', 18, 2)->default(0);
            $table->decimal('est_subcontract_cost', 18, 2)->default(0);

            // Actuals.
            $table->decimal('qty_completed', 18, 6)->default(0);
            $table->decimal('qty_scrapped', 18, 6)->default(0);
            $table->decimal('qty_reworked', 18, 6)->default(0);
            $table->decimal('act_material_cost', 18, 2)->default(0);
            $table->decimal('act_labour_cost', 18, 2)->default(0);
            $table->decimal('act_machine_cost', 18, 2)->default(0);
            $table->decimal('act_overhead_cost', 18, 2)->default(0);

            // Who moved it, and when -- the lifecycle the spec lists.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();

            /** The variance journal posted at close, so the books and the order point at each other. */
            $table->unsignedBigInteger('close_journal_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'mfg_po_status_idx');
            $table->index(['company_id', 'factory_id', 'due_date'], 'mfg_po_due_idx');
            $table->index(['company_id', 'item_id'], 'mfg_po_item_idx');
        });
        DB::statement('CREATE UNIQUE INDEX mfg_po_number_uniq ON mfg_production_orders (company_id, number)');

        /** What the order needs, copied from its BOM revision when it is created. */
        Schema::create('mfg_production_order_components', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('mfg_production_orders')->cascadeOnDelete();
            $table->foreignId('bom_line_id')->nullable()->constrained('mfg_bom_lines')->nullOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items');
            $table->string('line_kind', 16)->default('COMPONENT');
            $table->decimal('required_qty', 18, 6);
            $table->string('uom', 16)->nullable();
            $table->decimal('issued_qty', 18, 6)->default(0);
            $table->decimal('returned_qty', 18, 6)->default(0);
            $table->decimal('est_unit_cost', 18, 4)->default(0);
            $table->decimal('issued_value', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'order_id'], 'mfg_poc_order_idx');
            $table->index(['company_id', 'stock_item_id'], 'mfg_poc_item_idx');
        });

        /** What the order does, copied from its routing revision. */
        Schema::create('mfg_production_order_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('mfg_production_orders')->cascadeOnDelete();
            $table->foreignId('routing_operation_id')->nullable()->constrained('mfg_routing_operations')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('name');
            $table->foreignId('work_centre_id')->nullable()->constrained('mfg_work_centres')->nullOnDelete();
            $table->string('machine_type', 64)->nullable();
            $table->string('parallel_group', 16)->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_inspection_point')->default(false);
            $table->boolean('is_approval_gate')->default(false);
            $table->string('gate_permission', 128)->nullable();
            $table->boolean('is_subcontracted')->default(false);

            $table->decimal('planned_minutes', 12, 2)->default(0);
            $table->decimal('planned_labour_minutes', 12, 2)->default(0);

            $table->string('status', 16)->default('PENDING'); // PENDING|READY|IN_PROGRESS|PAUSED|COMPLETED|SKIPPED

            // Actuals, written on the shop floor (MFG-007).
            $table->decimal('qty_completed', 18, 6)->default(0);
            $table->decimal('qty_rejected', 18, 6)->default(0);
            $table->decimal('qty_scrapped', 18, 6)->default(0);
            $table->decimal('qty_reworked', 18, 6)->default(0);
            $table->decimal('actual_machine_minutes', 12, 2)->default(0);
            $table->decimal('actual_labour_minutes', 12, 2)->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            // The gate: inspection result or a signature.
            $table->string('gate_status', 12)->nullable(); // PASSED|FAILED
            $table->foreignId('gate_passed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('gate_passed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'order_id'], 'mfg_poo_order_idx');
            $table->index(['company_id', 'work_centre_id', 'status'], 'mfg_poo_wc_idx');
        });

        /**
         * Every change of state, every exception and every correction, with who and why. The
         * "controlled exceptions" the spec asks for are rows here, not quiet bypasses.
         */
        Schema::create('mfg_production_order_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('mfg_production_orders')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->boolean('is_exception')->default(false);
            $table->text('reason')->nullable();
            $table->jsonb('detail')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acted_at');
            $table->timestamps();

            $table->index(['company_id', 'order_id'], 'mfg_poe_order_idx');
        });

        // Policy switches approval reads (MFG-006: "according to configured policy").
        Schema::table('mfg_factory_policies', function (Blueprint $table) {
            $table->boolean('require_material_for_approval')->default(true);
            $table->boolean('block_on_capacity_conflict')->default(false);
            $table->boolean('require_routing')->default(true);
            /** Overhead charged as a share of labour and machine cost. NULL: none configured. */
            $table->decimal('overhead_percent', 8, 4)->nullable();
        });

        Schema::table('mfg_work_centres', function (Blueprint $table) {
            /** People cost per hour at this centre, separate from the machine's own rate. */
            $table->decimal('labour_rate_per_hour', 18, 4)->nullable();
        });

        $this->applyTenantRls(['mfg_production_orders', 'mfg_production_order_components',
            'mfg_production_order_operations', 'mfg_production_order_events']);
        $this->guardClosedOrders();
    }

    /**
     * "Closed orders must not be silently edited. Corrections must use audited adjustment or
     *  reversal workflows."
     *
     * A closed order, its components and its operations are refused any update or delete. A
     * correction is an event row (and, for stock, a reversing movement) -- the closed figures stay
     * exactly as they were closed.
     */
    private function guardClosedOrders(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mfg_refuse_closed_order() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'CLOSED' AND pg_trigger_depth() = 1 THEN
                    RAISE EXCEPTION 'Production order % is closed; record an adjustment instead of editing it', OLD.number;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION mfg_refuse_closed_order_child() RETURNS trigger AS $$
            DECLARE parent_status text;
            BEGIN
                SELECT status INTO parent_status FROM mfg_production_orders WHERE id = COALESCE(NEW.order_id, OLD.order_id);
                IF parent_status = 'CLOSED' AND pg_trigger_depth() = 1 THEN
                    RAISE EXCEPTION 'The production order is closed; record an adjustment instead of editing it';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$ LANGUAGE plpgsql;

            CREATE TRIGGER mfg_po_closed BEFORE UPDATE OR DELETE ON mfg_production_orders
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_closed_order();
            CREATE TRIGGER mfg_poc_closed BEFORE INSERT OR UPDATE OR DELETE ON mfg_production_order_components
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_closed_order_child();
            CREATE TRIGGER mfg_poo_closed BEFORE INSERT OR UPDATE OR DELETE ON mfg_production_order_operations
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_closed_order_child();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_po_closed ON mfg_production_orders');
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_poc_closed ON mfg_production_order_components');
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_poo_closed ON mfg_production_order_operations');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_closed_order()');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_closed_order_child()');
        }

        Schema::table('mfg_work_centres', fn (Blueprint $t) => $t->dropColumn('labour_rate_per_hour'));
        Schema::table('mfg_factory_policies', fn (Blueprint $t) => $t->dropColumn([
            'require_material_for_approval', 'block_on_capacity_conflict', 'require_routing', 'overhead_percent',
        ]));

        foreach (['mfg_production_order_events', 'mfg_production_order_operations',
            'mfg_production_order_components', 'mfg_production_orders'] as $t) {
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
