<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-050 to WMS-053: moving stock between bins and between warehouses, and keeping pick faces
 * topped up.
 *
 * A bin-to-bin move needs no table of its own: it is the ledger's transfer movement. A
 * warehouse-to-warehouse transfer does, because it lives for days -- approved, picked, on a truck,
 * partly received, reconciled -- and the stock on the truck must be somewhere the ledger can see
 * but nobody can sell: the destination's in-transit location.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_transfer_orders', 'wms_transfer_lines', 'wms_replenishment_rules', 'wms_replenishment_tasks'];

    public function up(): void
    {
        Schema::create('wms_transfer_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('from_warehouse_id')->constrained('fin_warehouses');
            $t->foreignId('to_warehouse_id')->constrained('fin_warehouses');
            // DRAFT|APPROVED|PICKED|DISPATCHED|PARTIALLY_RECEIVED|RECEIVED|RECONCILED|CANCELLED
            $t->string('status', 20)->default('DRAFT');
            $t->text('reason')->nullable();
            $t->unsignedSmallInteger('priority')->default(50);
            $t->date('needed_by')->nullable();
            $t->string('carrier', 120)->nullable();
            $t->string('vehicle_reg', 20)->nullable();
            $t->string('tracking_reference', 120)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('picked_at')->nullable();
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('received_at')->nullable();
            $t->timestampTz('reconciled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->string('source', 16)->default('MANUAL'); // MANUAL|REPLENISHMENT
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_transfer_number_uniq');
        });

        Schema::create('wms_transfer_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('transfer_id')->constrained('wms_transfer_orders')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->foreignId('from_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('to_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->decimal('picked_qty', 18, 4)->default(0);
            $t->decimal('dispatched_qty', 18, 4)->default(0);
            $t->decimal('received_qty', 18, 4)->default(0);
            $t->decimal('variance_qty', 18, 4)->default(0);
            $t->text('variance_note')->nullable();
            $t->timestampsTz();
        });

        Schema::create('wms_replenishment_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->foreignId('pick_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->string('source', 12)->default('BULK'); // BULK (same warehouse) | WAREHOUSE (another)
            $t->foreignId('source_warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
            $t->decimal('min_qty', 18, 4);
            $t->decimal('max_qty', 18, 4);
            $t->decimal('multiple', 18, 4)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->unique(['warehouse_id', 'item_id', 'pick_bin_id'], 'wms_repl_rule_uniq');
        });

        Schema::create('wms_replenishment_tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('rule_id')->nullable()->constrained('wms_replenishment_rules')->nullOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->foreignId('from_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('to_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->decimal('done_qty', 18, 4)->default(0);
            $t->unsignedSmallInteger('priority')->default(50);
            $t->jsonb('priority_reasons')->nullable();
            $t->string('status', 12)->default('OPEN'); // OPEN|DONE|PARTIAL|FAILED|CANCELLED
            $t->foreignId('transfer_id')->nullable()->constrained('wms_transfer_orders')->nullOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('completed_at')->nullable();
            $t->text('exception_note')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_repl_task_number_uniq');
            $t->index(['warehouse_id', 'status', 'priority'], 'wms_repl_task_queue_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wms_transfer_orders ADD CONSTRAINT wms_transfer_two_places CHECK (from_warehouse_id <> to_warehouse_id)');
            DB::statement('ALTER TABLE wms_transfer_lines ADD CONSTRAINT wms_transfer_line_qty CHECK (qty > 0 AND received_qty <= dispatched_qty AND dispatched_qty <= qty)');
            DB::statement('ALTER TABLE wms_replenishment_rules ADD CONSTRAINT wms_repl_min_max CHECK (min_qty >= 0 AND max_qty > min_qty)');
            // Only one open replenishment per rule: generating twice must not double the work.
            DB::statement("CREATE UNIQUE INDEX wms_repl_one_open_per_rule ON wms_replenishment_tasks (rule_id) WHERE status = 'OPEN' AND rule_id IS NOT NULL");

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
        foreach (['wms_replenishment_tasks', 'wms_replenishment_rules', 'wms_transfer_lines', 'wms_transfer_orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
