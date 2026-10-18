<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-location inventory. Quantity is tracked per warehouse (fin_stock_balances); costing/value
 * stays at the item level (company-wide FIFO/avg), which is the standard mid-market split. Stock
 * movements are stamped with the warehouse they affected (and a second warehouse for transfers).
 *
 * Backfill: every company that already has stock items gets a default "Main Warehouse", each
 * item's current qty_on_hand is seeded there, and existing movements are stamped to it — so the
 * upgrade is seamless and nothing is stranded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_warehouses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('code', 40);
            $t->string('name');
            $t->string('address')->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('fin_stock_balances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('qty', 18, 4)->default(0);
            $t->timestamps();
            $t->unique(['item_id', 'warehouse_id']);
            $t->index('warehouse_id');
        });

        Schema::table('fin_stock_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('warehouse_id')->nullable()->after('item_id');
            $t->unsignedBigInteger('to_warehouse_id')->nullable()->after('warehouse_id'); // transfer destination
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['fin_warehouses', 'fin_stock_balances'] as $table) {
                $session = "NULLIF(current_setting('app.company_id', true), '')";
                $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }

        // Backfill a default warehouse per company and seed current stock into it.
        foreach (DB::table('fin_stock_items')->select('company_id')->distinct()->pluck('company_id') as $companyId) {
            $warehouseId = DB::table('fin_warehouses')->insertGetId([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'code' => 'MAIN',
                'name' => 'Main Warehouse', 'is_default' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach (DB::table('fin_stock_items')->where('company_id', $companyId)->get(['id', 'qty_on_hand']) as $item) {
                DB::table('fin_stock_balances')->insert([
                    'company_id' => $companyId, 'item_id' => $item->id, 'warehouse_id' => $warehouseId,
                    'qty' => $item->qty_on_hand, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('fin_stock_movements')
                ->whereIn('item_id', fn ($q) => $q->select('id')->from('fin_stock_items')->where('company_id', $companyId))
                ->update(['warehouse_id' => $warehouseId]);
        }
    }

    public function down(): void
    {
        Schema::table('fin_stock_movements', function (Blueprint $t) {
            $t->dropColumn(['warehouse_id', 'to_warehouse_id']);
        });
        Schema::dropIfExists('fin_stock_balances');
        Schema::dropIfExists('fin_warehouses');
    }
};
