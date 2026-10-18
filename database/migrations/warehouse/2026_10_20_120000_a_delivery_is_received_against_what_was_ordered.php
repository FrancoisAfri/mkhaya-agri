<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-030 to WMS-035: inbound, built on Procurement's purchase orders and goods-received notes.
 *
 * A WHMI receipt IS a `goods_received_notes` row: Procurement's received quantities, budget
 * consumption and three-way match keep working exactly as they do, and the GRN now also posts the
 * stock (WHMI decision 2). A purchase-order line learns which stock item it buys; a line that names
 * no stock item -- a service, an asset -- is received the old way and posts nothing.
 *
 * The stock side of a receipt credits Goods Received Not Invoiced (2150), not Accounts Payable:
 * the supplier's bill credits AP when it arrives, and crediting it on receipt as well would owe the
 * supplier twice.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'wms_receipt_discrepancies', 'wms_dock_appointments', 'wms_supplier_returns', 'wms_supplier_return_lines',
        'wms_putaway_tasks', 'wms_receipt_attachments',
    ];

    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
        });
        Schema::table('purchase_order_items', function (Blueprint $t) {
            $t->foreignId('stock_item_id')->nullable()->constrained('fin_stock_items')->nullOnDelete();
            $t->foreignId('uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
        });

        Schema::table('goods_received_notes', function (Blueprint $t) {
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->string('source', 8)->default('PO'); // PO|NON_PO
            $t->text('non_po_reason')->nullable();
            $t->string('approval_status', 12)->default('NOT_REQUIRED'); // NOT_REQUIRED|PENDING|APPROVED|REJECTED
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->string('delivery_note_ref', 80)->nullable();
            $t->string('vehicle_reg', 20)->nullable();
            $t->foreignId('appointment_id')->nullable();
            $t->string('discrepancy_status', 12)->default('NONE'); // NONE|OPEN|RESOLVED
            $t->string('inspection_status', 12)->default('NOT_REQUIRED'); // NOT_REQUIRED|PENDING|PASSED|FAILED
            $t->timestampTz('posted_at')->nullable();
            $t->string('idempotency_key', 120)->nullable();
        });
        DB::statement('ALTER TABLE goods_received_notes ALTER COLUMN purchase_order_id DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX grn_idem_uniq ON goods_received_notes (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        Schema::table('goods_received_items', function (Blueprint $t) {
            $t->foreignId('stock_item_id')->nullable()->constrained('fin_stock_items')->nullOnDelete();
            $t->string('description')->nullable();
            $t->decimal('ordered_qty', 18, 4)->nullable();
            $t->decimal('damaged_qty', 18, 4)->default(0);
            $t->decimal('missing_qty', 18, 4)->default(0);
            $t->decimal('excess_qty', 18, 4)->default(0);
            $t->foreignId('txn_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
            $t->decimal('base_qty', 18, 4)->nullable();
            $t->decimal('unit_cost', 18, 4)->nullable();
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->jsonb('serial_numbers')->nullable();
            $t->date('manufactured_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('stock_status', 16)->nullable();
            $t->foreignId('movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
        });

        Schema::create('wms_receipt_discrepancies', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('grn_id')->constrained('goods_received_notes')->cascadeOnDelete();
            $t->foreignId('grn_item_id')->nullable()->constrained('goods_received_items')->cascadeOnDelete();
            $t->string('kind', 20); // UNDER|OVER|WRONG_ITEM|DAMAGED|MISSING|DUPLICATE|SHORT_SHELF_LIFE|UNEXPECTED|PRICE|DOCUMENT
            $t->decimal('expected', 18, 4)->nullable();
            $t->decimal('actual', 18, 4)->nullable();
            $t->boolean('within_tolerance')->default(false);
            $t->text('detail')->nullable();
            $t->string('status', 12)->default('OPEN'); // OPEN|ACCEPTED|RESOLVED|REJECTED
            $t->text('resolution')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampTz('notified_at')->nullable();
            $t->timestampsTz();
        });

        Schema::create('wms_dock_appointments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->foreignId('dock_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->timestampTz('slot_start');
            $t->timestampTz('slot_end');
            $t->string('carrier', 120)->nullable();
            $t->string('vehicle_reg', 20)->nullable();
            $t->string('driver_name', 120)->nullable();
            $t->string('driver_phone', 40)->nullable();
            $t->string('status', 12)->default('BOOKED'); // BOOKED|ARRIVED|UNLOADING|COMPLETED|NO_SHOW|CANCELLED
            $t->timestampTz('arrived_at')->nullable();
            $t->timestampTz('unloading_started_at')->nullable();
            $t->timestampTz('departed_at')->nullable();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_appt_number_uniq');
            $t->index(['warehouse_id', 'slot_start'], 'wms_appt_slot_idx');
        });

        Schema::create('wms_supplier_returns', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->foreignId('grn_id')->nullable()->constrained('goods_received_notes')->nullOnDelete();
            $t->string('status', 14)->default('DRAFT'); // DRAFT|APPROVED|DISPATCHED|ACKNOWLEDGED|CLOSED|CANCELLED
            $t->text('reason');
            $t->string('carrier', 120)->nullable();
            $t->string('dispatch_reference', 120)->nullable();
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('acknowledged_at')->nullable();
            $t->string('acknowledged_by_name', 160)->nullable();
            $t->string('outcome', 12)->nullable(); // REPLACEMENT|CREDIT|NONE
            $t->string('credit_reference', 120)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_sret_number_uniq');
        });

        Schema::create('wms_supplier_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('return_id')->constrained('wms_supplier_returns')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('grn_item_id')->nullable()->constrained('goods_received_items')->nullOnDelete();
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->string('stock_status', 16)->default('REJECTED');
            $t->decimal('qty', 18, 4);
            $t->jsonb('serial_numbers')->nullable();
            $t->foreignId('movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
            $t->timestampsTz();
        });

        /*
         * Put-away tasks (WMS-040/041). Created here because receiving initiates them (WMS-032
         * step 11); step 5 directs and completes them.
         */
        Schema::create('wms_putaway_tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->string('stock_status', 16)->default('AVAILABLE');
            $t->foreignId('from_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('suggested_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('to_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->decimal('done_qty', 18, 4)->default(0);
            $t->string('status', 12)->default('OPEN'); // OPEN|IN_PROGRESS|DONE|PARTIAL|FAILED|CANCELLED
            $t->unsignedSmallInteger('priority')->default(50);
            $t->string('source_type', 32)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->text('exception_note')->nullable();
            $t->jsonb('suggestion_reasons')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_putaway_number_uniq');
            $t->index(['warehouse_id', 'status'], 'wms_putaway_open_idx');
        });

        Schema::create('wms_receipt_attachments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('grn_id')->constrained('goods_received_notes')->cascadeOnDelete();
            $t->string('kind', 16)->default('DOCUMENT'); // DOCUMENT|PHOTO
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wms_putaway_tasks ADD CONSTRAINT wms_putaway_qty_positive CHECK (qty > 0 AND done_qty >= 0 AND done_qty <= qty)");
            DB::statement("ALTER TABLE wms_supplier_return_lines ADD CONSTRAINT wms_sret_qty_positive CHECK (qty > 0)");
            DB::statement("ALTER TABLE wms_dock_appointments ADD CONSTRAINT wms_appt_slot_order CHECK (slot_end > slot_start)");

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

        $this->addGrniAccount();
    }

    public function down(): void
    {
        foreach (['wms_receipt_attachments', 'wms_putaway_tasks', 'wms_supplier_return_lines', 'wms_supplier_returns', 'wms_dock_appointments', 'wms_receipt_discrepancies'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('goods_received_items', function (Blueprint $t) {
            foreach (['stock_item_id', 'txn_uom_id', 'bin_id', 'lot_id', 'movement_id'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['description', 'ordered_qty', 'damaged_qty', 'missing_qty', 'excess_qty', 'base_qty', 'unit_cost',
                'serial_numbers', 'manufactured_on', 'expires_on', 'stock_status']);
        });
        DB::statement('DROP INDEX IF EXISTS grn_idem_uniq');
        Schema::table('goods_received_notes', function (Blueprint $t) {
            foreach (['warehouse_id', 'supplier_id', 'approved_by'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['source', 'non_po_reason', 'approval_status', 'approved_at', 'delivery_note_ref', 'vehicle_reg',
                'appointment_id', 'discrepancy_status', 'inspection_status', 'posted_at', 'idempotency_key']);
        });
        Schema::table('purchase_order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('stock_item_id');
            $t->dropConstrainedForeignId('uom_id');
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('warehouse_id');
        });
    }

    /** 2150 Goods Received Not Invoiced, in every chart that has an inventory account. */
    private function addGrniAccount(): void
    {
        foreach (DB::table('fin_accounts')->where('code', '1300')->get(['company_id', 'entity_id']) as $chart) {
            $exists = DB::table('fin_accounts')->where('code', '2150')
                ->where(fn ($q) => $chart->company_id === null ? $q->whereNull('company_id') : $q->where('company_id', $chart->company_id))
                ->where(fn ($q) => $chart->entity_id === null ? $q->whereNull('entity_id') : $q->where('entity_id', $chart->entity_id))
                ->exists();
            if (! $exists) {
                DB::table('fin_accounts')->insert([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $chart->company_id, 'entity_id' => $chart->entity_id,
                    'code' => '2150', 'name' => 'Goods Received Not Invoiced', 'type' => 'liability', 'normal_balance' => 'credit',
                    'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
};
