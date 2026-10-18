<?php

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-017 -- sales, order fulfilment and subcontracting.
 *
 * The sales order stays Finance's: manufacturing never writes to it and never invoices. What
 * manufacturing keeps is its own promise against a sales order line -- the date the customer
 * needs it, the date production can make it, how the product is made (stock, to order, or
 * configured to order) -- in mfg_customer_commitments. Finished stock is held for the line by an
 * ordinary stock reservation (source SALES_ORDER_LINE); production for it is a production order
 * whose demand is that line.
 *
 * Subcontracting sends work out: either one subcontracted operation of an order (the work in
 * progress goes to the supplier and comes back) or the whole order (the supplier makes it from
 * material we send). The material sent is issued to the production order -- it is WIP that happens
 * to be at the supplier -- and what comes back is inspected before anything downstream can use it.
 * The supplier's charge reaches procurement as a draft purchase request; the supplier's invoice is
 * procurement's and accounts payable's, never this module's.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_customer_commitments', 'mfg_subcontract_orders', 'mfg_subcontract_materials', 'mfg_subcontract_receipts'];

    public function up(): void
    {
        Schema::table('mfg_items', function (Blueprint $table) {
            $table->string('production_strategy', 3)->default('MTS');   // MTS|MTO|CTO
        });

        Schema::create('mfg_customer_commitments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained('fin_sales_orders')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('fin_sales_order_lines')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items');
            $table->foreignId('item_id')->constrained('mfg_items');
            $table->foreignId('warehouse_id')->constrained('fin_warehouses');
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->nullOnDelete();
            $table->string('strategy', 3);
            $table->date('required_date');
            $table->date('promised_date')->nullable();
            $table->unsignedSmallInteger('lead_days')->nullable();
            $table->text('configuration')->nullable();
            $table->string('status', 12)->default('OPEN');   // OPEN|CANCELLED
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'sales_order_line_id'], 'mfg_commitment_line_uq');
        });

        Schema::create('mfg_subcontract_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('production_order_id')->constrained('mfg_production_orders')->cascadeOnDelete();
            // Null: the whole order is made by the supplier.
            $table->foreignId('order_operation_id')->nullable()->constrained('mfg_production_order_operations')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->decimal('qty', 18, 6);
            $table->decimal('qty_sent', 18, 6)->default(0);
            $table->decimal('qty_received', 18, 6)->default(0);
            $table->decimal('qty_rejected', 18, 6)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('DRAFT');   // DRAFT|SENT|PART_RECEIVED|RECEIVED|CLOSED|CANCELLED
            $table->unsignedBigInteger('purchase_request_id')->nullable();
            $table->string('dispatch_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'mfg_subcontract_number_uq');
        });

        Schema::create('mfg_subcontract_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('subcontract_order_id')->constrained('mfg_subcontract_orders')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('mfg_production_order_components')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items');
            $table->decimal('qty_planned', 18, 6)->default(0);
            $table->decimal('qty_sent', 18, 6)->default(0);
            $table->decimal('qty_returned', 18, 6)->default(0);
            $table->timestamps();

            $table->unique(['subcontract_order_id', 'component_id'], 'mfg_subcontract_material_uq');
        });

        Schema::create('mfg_subcontract_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('subcontract_order_id')->constrained('mfg_subcontract_orders')->cascadeOnDelete();
            $table->decimal('qty', 18, 6);
            $table->string('supplier_reference')->nullable();
            $table->foreignId('inspection_id')->nullable()->constrained('mfg_inspections')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE mfg_items ADD CONSTRAINT mfg_items_strategy_chk CHECK (production_strategy IN ('MTS','MTO','CTO'))");
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TABLES as $t) {
                DB::statement("ALTER TABLE {$t} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
                DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }

        (new ManufacturingModuleSeeder)->run();
        $installer = app(ManufacturingReferenceData::class);
        foreach (DB::table('mfg_number_sequences')->whereNotNull('company_id')->distinct()->pluck('company_id') as $companyId) {
            $installer->installFor((int) $companyId);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE mfg_items DROP CONSTRAINT IF EXISTS mfg_items_strategy_chk');
        }
        Schema::table('mfg_items', fn (Blueprint $table) => $table->dropColumn('production_strategy'));
    }
};
