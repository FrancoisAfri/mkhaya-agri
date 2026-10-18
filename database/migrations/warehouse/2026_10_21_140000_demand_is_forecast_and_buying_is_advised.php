<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-120 to WMS-124: forecasts and purchasing advice.
 *
 * A forecast run is kept whole -- the method, the history it stood on, what it treated as a one-off
 * -- so its accuracy can be judged later against what actually happened, and an override keeps both
 * numbers and the reason. Supplier terms (minimum order, multiple, lead time) are per supplier and
 * item; the product-wide minimum stays Manufacturing's planning parameter.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_forecast_runs', 'wms_forecasts', 'wms_supplier_terms'];

    public function up(): void
    {
        Schema::create('wms_forecast_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
            $t->string('bucket', 6)->default('MONTH'); // WEEK|MONTH
            $t->unsignedSmallInteger('horizon');
            $t->unsignedSmallInteger('history_periods');
            $t->jsonb('params')->nullable();
            $t->unsignedInteger('items')->default(0);
            $t->unsignedInteger('insufficient')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('wms_forecasts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('run_id')->constrained('wms_forecast_runs')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->date('period_start');
            $t->string('method', 16); // MOVING_AVERAGE|SMOOTHING|SEASONAL|CROSTON|INSUFFICIENT
            $t->decimal('qty', 18, 4)->nullable(); // null when the history cannot support a number
            $t->decimal('lower', 18, 4)->nullable();
            $t->decimal('upper', 18, 4)->nullable();
            $t->decimal('override_qty', 18, 4)->nullable();
            $t->string('override_reason', 500)->nullable();
            $t->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $t->jsonb('basis')->nullable(); // history used, one-offs treated, demand pattern
            $t->timestampsTz();
            $t->unique(['run_id', 'item_id', 'period_start'], 'wms_forecast_uniq');
            $t->index(['company_id', 'item_id', 'period_start'], 'wms_forecast_item_period');
        });
        Schema::create('wms_supplier_terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->decimal('min_order_qty', 18, 4)->nullable();
            $t->decimal('order_multiple', 18, 4)->nullable();
            $t->unsignedSmallInteger('lead_time_days')->nullable();
            $t->decimal('unit_price', 18, 4)->nullable(); // agreed price per base unit, where there is one
            $t->string('notes', 500)->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'supplier_id', 'stock_item_id'], 'wms_supplier_terms_uniq');
        });

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
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
