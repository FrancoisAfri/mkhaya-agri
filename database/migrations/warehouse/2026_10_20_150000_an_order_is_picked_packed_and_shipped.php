<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-060 to WMS-066: fulfilment -- demand from every source, allocation, picking, packing,
 * dispatch and the service levels around them.
 *
 * A fulfilment order is the warehouse's view of demand that already lives elsewhere: a Finance
 * sales order, a production order's components, an internal issue, an order from a web shop or
 * another system. `(source, external_ref)` is unique per company, so the same demand arriving
 * twice -- a retried API call, a double-clicked import -- is refused instead of shipped twice.
 *
 * Allocation reserves through the ledger's own reservations; a pick moves stock to the packing
 * area through the ledger; dispatch issues it through the ledger, which is where stock leaves the
 * books for a WHMI-run warehouse (WHMI decision 3).
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_orders', 'wms_order_lines', 'wms_allocations', 'wms_pick_waves', 'wms_pick_tasks',
        'wms_packages', 'wms_package_contents', 'wms_shipments', 'wms_shipment_packages'];

    public function up(): void
    {
        Schema::create('wms_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->string('source', 16); // SALES_ORDER|ECOMMERCE|INTERNAL|MANUFACTURING|API
            $t->string('external_ref', 120);
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('customer_party_id')->nullable()->constrained('fin_parties')->nullOnDelete();
            $t->string('customer_name', 160)->nullable();
            $t->jsonb('ship_to')->nullable();
            $t->unsignedSmallInteger('priority')->default(50);
            $t->timestampTz('required_by')->nullable();
            $t->timestampTz('sla_due_at')->nullable();
            $t->timestampTz('sla_at_risk_at')->nullable();
            $t->boolean('allow_partial')->default(true);
            $t->boolean('allow_substitution')->default(false);
            // NEW|RELEASED|ALLOCATED|PARTIALLY_ALLOCATED|BACKORDERED|PICKING|PICKED|PACKED|PARTIALLY_DISPATCHED|DISPATCHED|DELIVERED|CANCELLED
            $t->string('status', 24)->default('NEW');
            $t->string('carrier', 120)->nullable();
            $t->text('notes')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('released_at')->nullable();
            $t->timestampTz('pick_started_at')->nullable();
            $t->timestampTz('picked_at')->nullable();
            $t->timestampTz('packed_at')->nullable();
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_order_number_uniq');
            $t->unique(['company_id', 'source', 'external_ref'], 'wms_order_no_duplicate_demand');
            $t->index(['warehouse_id', 'status'], 'wms_order_queue_idx');
        });

        Schema::create('wms_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('wms_orders')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->decimal('qty', 18, 4);
            $t->foreignId('txn_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
            $t->decimal('txn_qty', 18, 4)->nullable();
            $t->decimal('qty_allocated', 18, 4)->default(0);
            $t->decimal('qty_picked', 18, 4)->default(0);
            $t->decimal('qty_packed', 18, 4)->default(0);
            $t->decimal('qty_dispatched', 18, 4)->default(0);
            $t->decimal('qty_cancelled', 18, 4)->default(0);
            $t->decimal('qty_short', 18, 4)->default(0);
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete(); // a line that must be one lot
            $t->foreignId('substitute_of_line_id')->nullable()->constrained('wms_order_lines')->nullOnDelete();
            $t->unsignedBigInteger('source_line_id')->nullable();
            $t->timestampsTz();
        });

        Schema::create('wms_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('order_line_id')->constrained('wms_order_lines')->cascadeOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained('fin_stock_reservations')->nullOnDelete();
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->decimal('qty_picked', 18, 4)->default(0);
            $t->decimal('qty_dispatched', 18, 4)->default(0);
            $t->string('status', 12)->default('ACTIVE'); // ACTIVE|PICKED|DISPATCHED|RELEASED
            $t->text('release_reason')->nullable();
            $t->timestampsTz();
            $t->index(['bin_id', 'lot_id', 'status'], 'wms_alloc_bin_idx');
        });

        Schema::create('wms_pick_waves', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->string('strategy', 12); // DISCRETE|BATCH|ZONE|WAVE|CLUSTER
            $t->string('status', 12)->default('OPEN'); // OPEN|IN_PROGRESS|DONE|CANCELLED
            $t->string('zone', 32)->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_wave_number_uniq');
        });

        Schema::create('wms_pick_tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('wave_id')->nullable()->constrained('wms_pick_waves')->nullOnDelete();
            $t->foreignId('order_id')->constrained('wms_orders')->cascadeOnDelete();
            $t->foreignId('order_line_id')->constrained('wms_order_lines')->cascadeOnDelete();
            $t->foreignId('allocation_id')->constrained('wms_allocations')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('to_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->decimal('picked_qty', 18, 4)->default(0);
            $t->decimal('short_qty', 18, 4)->default(0);
            $t->unsignedInteger('sequence')->default(0);
            $t->string('zone', 32)->nullable();
            $t->unsignedSmallInteger('cluster_slot')->nullable();
            $t->string('status', 12)->default('OPEN'); // OPEN|PICKED|SHORT|CANCELLED
            $t->unsignedSmallInteger('scan_errors')->default(0);
            $t->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->text('note')->nullable();
            $t->timestampsTz();
            $t->index(['wave_id', 'sequence'], 'wms_pick_path_idx');
        });

        Schema::create('wms_packages', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('wms_orders')->cascadeOnDelete();
            $t->string('sscc', 18);
            $t->string('type', 12)->default('CARTON'); // CARTON|PALLET|BAG|ENVELOPE|OTHER
            $t->decimal('weight_kg', 12, 3)->nullable();
            $t->decimal('length_cm', 10, 2)->nullable();
            $t->decimal('width_cm', 10, 2)->nullable();
            $t->decimal('height_cm', 10, 2)->nullable();
            $t->string('tracking_number', 120)->nullable();
            $t->string('status', 12)->default('OPEN'); // OPEN|CLOSED|DISPATCHED
            $t->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'sscc'], 'wms_package_sscc_uniq');
        });

        Schema::create('wms_package_contents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('package_id')->constrained('wms_packages')->cascadeOnDelete();
            $t->foreignId('order_line_id')->constrained('wms_order_lines')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->timestampsTz();
        });

        Schema::create('wms_shipments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('order_id')->constrained('wms_orders')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->string('carrier', 120)->nullable();
            $t->string('service', 80)->nullable();
            $t->string('vehicle_reg', 20)->nullable();
            $t->string('driver_name', 120)->nullable();
            $t->string('tracking_reference', 120)->nullable();
            $t->timestampTz('collection_at')->nullable();
            $t->timestampTz('dispatched_at')->nullable();
            $t->string('handover_name', 160)->nullable();
            $t->string('handover_signature_path')->nullable();
            $t->unsignedBigInteger('delivery_note_id')->nullable(); // Finance's fin_delivery_notes for a sales order
            $t->string('status', 12)->default('DISPATCHED'); // DISPATCHED|DELIVERED|FAILED
            $t->timestampTz('delivered_at')->nullable();
            $t->string('pod_received_by', 160)->nullable();
            $t->string('pod_path')->nullable();
            $t->text('pod_note')->nullable();
            $t->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_shipment_number_uniq');
        });

        Schema::create('wms_shipment_packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('shipment_id')->constrained('wms_shipments')->cascadeOnDelete();
            $t->foreignId('package_id')->constrained('wms_packages')->cascadeOnDelete();
            $t->unique(['package_id'], 'wms_package_ships_once');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wms_order_lines ADD CONSTRAINT wms_line_quantities CHECK (qty > 0 AND qty_dispatched <= qty_picked + 0.0001 AND qty_packed <= qty_picked + 0.0001)');
            DB::statement('ALTER TABLE wms_allocations ADD CONSTRAINT wms_alloc_qty CHECK (qty > 0 AND qty_picked <= qty + 0.0001)');
            DB::statement('ALTER TABLE wms_pick_tasks ADD CONSTRAINT wms_pick_qty CHECK (qty > 0 AND picked_qty + short_qty <= qty + 0.0001)');

            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TENANT_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
