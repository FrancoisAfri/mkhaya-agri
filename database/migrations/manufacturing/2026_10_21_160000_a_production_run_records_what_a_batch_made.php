<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A production run: an order recorded after the batch, from what actually went in and came out.
 *
 * Grading, sorting and packing produce are not made to a bill of materials. A bin of tomatoes is
 * graded into class 1, class 2 and juice, in whatever shares the fruit gives; a carton is packed
 * from the class 1 it was graded into. So a run has no BOM: its components are the lots and the
 * packaging actually used, and it has as many outputs as the batch made — each its own stock item,
 * lot and grade, carrying its share of the cost. It is an ordinary production order otherwise:
 * numbered, evented, and traced through the stock ledger like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfg_production_orders', function (Blueprint $t) {
            $t->string('order_type', 12)->default('STANDARD');                   // STANDARD | RUN
        });
        DB::statement('ALTER TABLE mfg_production_orders ALTER COLUMN bom_id DROP NOT NULL');

        Schema::create('mfg_production_order_outputs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('mfg_production_orders')->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained('fin_stock_items');
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->unsignedBigInteger('lot_id')->nullable();                        // fin_stock_lots
            $t->unsignedBigInteger('movement_id')->nullable();                   // the receipt
            $t->string('grade', 40)->nullable();
            $t->decimal('qty', 18, 6);
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->decimal('value', 18, 2)->default(0);
            $t->string('stock_status', 12)->default('AVAILABLE');               // AVAILABLE | QUARANTINE
            $t->timestamps();
            $t->index(['company_id', 'order_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE mfg_production_order_outputs ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE mfg_production_order_outputs ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE mfg_production_order_outputs FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON mfg_production_order_outputs');
            DB::statement("CREATE POLICY tenant_isolation ON mfg_production_order_outputs USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_production_order_outputs');
        Schema::table('mfg_production_orders', fn (Blueprint $t) => $t->dropColumn('order_type'));
    }
};
