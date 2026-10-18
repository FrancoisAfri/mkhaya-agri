<?php

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-004, MFG-005, MFG-015 -- demand planning, material requirements planning and the bridge to
 * procurement.
 *
 * A plan run is a calculation, stored whole so a planner can review it: what each item needs,
 * when, against what supply, and the purchases and production it recommends. Nothing is ordered
 * or committed until a planner approves a recommendation -- production recommendations become
 * draft production orders, purchase recommendations become draft purchase requests in the existing
 * procurement workflow, with its approvals and limits intact.
 *
 * Purchase request lines gain a link to the stock item; purchase order lines get theirs from the
 * warehouse receiving migration, and supply on order is counted from it.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_planning_params', 'mfg_forecasts', 'mfg_plan_runs', 'mfg_plan_items', 'mfg_plan_recommendations'];

    public function up(): void
    {
        Schema::create('mfg_planning_params', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->string('make_or_buy', 4)->nullable();              // MAKE|BUY; empty: MAKE if it has a BOM
            $table->unsignedInteger('lead_time_days')->nullable();     // overrides the warehouse profile
            $table->decimal('safety_stock', 18, 4)->nullable();        // overrides the warehouse profile
            $table->decimal('min_order_qty', 18, 4)->nullable();
            $table->decimal('order_multiple', 18, 4)->nullable();
            $table->unsignedBigInteger('preferred_supplier_id')->nullable();
            $table->boolean('is_planned')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'stock_item_id'], 'mfg_planparam_item_uq');
        });

        Schema::create('mfg_forecasts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->nullOnDelete();
            $table->date('period_start');                 // the month the forecast covers
            $table->decimal('qty', 18, 4);
            $table->string('status', 10)->default('DRAFT'); // DRAFT|APPROVED
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'stock_item_id', 'period_start'], 'mfg_forecast_item_idx');
        });

        Schema::create('mfg_plan_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 48);
            $table->date('starts_on');
            $table->unsignedSmallInteger('horizon_weeks');
            $table->string('status', 10)->default('OPEN'); // OPEN|SUPERSEDED
            $table->jsonb('summary')->nullable();
            $table->jsonb('exceptions')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'mfg_planrun_number_uq');
        });

        Schema::create('mfg_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('mfg_plan_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_item_id');
            $table->unsignedSmallInteger('level')->default(0);  // low-level code: 0 = sold, deeper = used inside
            $table->string('make_or_buy', 4);
            $table->decimal('on_hand', 18, 4)->default(0);
            $table->decimal('safety_stock', 18, 4)->default(0);
            $table->decimal('gross', 18, 4)->default(0);
            $table->decimal('scheduled_receipts', 18, 4)->default(0);
            $table->decimal('net', 18, 4)->default(0);
            $table->decimal('planned', 18, 4)->default(0);
            $table->decimal('projected_end', 18, 4)->default(0);
            $table->date('shortage_on')->nullable();      // first bucket the item would go short without the plan
            $table->date('available_on')->nullable();     // when the full requirement can be met
            $table->jsonb('timeline');                    // per bucket: gross, receipts, planned, projected, net
            $table->jsonb('demand');                      // pegging: what the gross requirement is made of
            $table->jsonb('exceptions')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'level'], 'mfg_planitem_run_idx');
        });

        Schema::create('mfg_plan_recommendations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('mfg_plan_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_item_id');
            $table->string('kind', 8);                     // PURCHASE|PRODUCE
            $table->decimal('qty', 18, 4);
            $table->decimal('net_qty', 18, 4);             // before minimum order and multiples
            $table->date('need_by');
            $table->date('release_by');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->string('status', 10)->default('PROPOSED'); // PROPOSED|APPROVED|REJECTED|CONVERTED
            $table->decimal('override_qty', 18, 4)->nullable();
            $table->date('override_date')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('converted_type', 20)->nullable(); // PURCHASE_REQUEST|PRODUCTION_ORDER
            $table->unsignedBigInteger('converted_id')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status'], 'mfg_planrec_run_idx');
        });

        // The material a requested line is for, so a request MRP raises still names its item when it
        // becomes an order. (Purchase order lines get the same column from the warehouse receiving
        // migration; the planner reads it once it exists.)
        foreach (['purchase_request_items'] as $t) {
            if (! Schema::hasColumn($t, 'stock_item_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->foreignId('stock_item_id')->nullable()->constrained('fin_stock_items')->nullOnDelete());
            }
        }

        if (DB::getDriverName() === 'pgsql') {
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
        foreach (['purchase_request_items'] as $t) {
            if (Schema::hasColumn($t, 'stock_item_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_item_id'));
            }
        }
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
