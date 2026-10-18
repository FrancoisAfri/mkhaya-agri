<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-011 -- costing and profitability.
 *
 * A standard cost is calculated from the approved BOM and routing, priced line by line, and then
 * APPROVED -- a number somebody signed, not one recomputed every time a price moves. Each line keeps
 * the quantity, rate and basis it was priced on, so a variance can always be traced back to the
 * standard it was measured against.
 *
 * Variances are calculated per order and frozen when the order closes, each with its method and the
 * records it was calculated from.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_standard_costs', 'mfg_standard_cost_lines', 'mfg_order_variances'];

    public function up(): void
    {
        Schema::create('mfg_standard_costs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 12)->default('DRAFT'); // DRAFT|APPROVED|SUPERSEDED
            $table->decimal('costing_qty', 18, 6);           // the lot size the standard is built for
            $table->foreignId('bom_id')->nullable()->constrained('mfg_boms')->nullOnDelete();
            $table->foreignId('routing_id')->nullable()->constrained('mfg_routings')->nullOnDelete();
            $table->decimal('expected_output', 18, 6);       // after the routing's standard yield
            $table->decimal('material_cost', 18, 4)->default(0);
            $table->decimal('labour_cost', 18, 4)->default(0);
            $table->decimal('machine_cost', 18, 4)->default(0);
            $table->decimal('overhead_cost', 18, 4)->default(0);
            $table->decimal('subcontract_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('labour_minutes', 18, 4)->default(0);
            $table->decimal('machine_minutes', 18, 4)->default(0);
            $table->decimal('overhead_percent', 8, 4)->nullable();
            $table->jsonb('warnings')->nullable();            // prices that were missing or fell back
            $table->date('effective_from')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'item_id', 'version'], 'mfg_stdcost_version_uq');
        });

        Schema::create('mfg_standard_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('standard_cost_id')->constrained('mfg_standard_costs')->cascadeOnDelete();
            $table->string('category', 12); // MATERIAL|LABOUR|MACHINE|OVERHEAD|SUBCONTRACT
            $table->string('reference');     // what it is: a material code, an operation
            $table->unsignedBigInteger('stock_item_id')->nullable();
            $table->unsignedBigInteger('routing_operation_id')->nullable();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->string('uom', 24)->nullable();
            $table->decimal('rate', 18, 6)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('basis');         // how the rate was found
            $table->timestamps();
        });

        Schema::create('mfg_order_variances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('mfg_production_orders')->cascadeOnDelete();
            $table->foreignId('standard_cost_id')->nullable()->constrained('mfg_standard_costs')->nullOnDelete();
            // MATERIAL_PRICE|MATERIAL_USAGE|LABOUR_RATE|LABOUR_EFFICIENCY|MACHINE|YIELD|SCRAP_REWORK|OVERHEAD|TOTAL
            $table->string('kind', 20);
            $table->decimal('amount', 18, 4);   // positive = adverse (cost more than standard)
            $table->text('method');
            $table->jsonb('inputs');           // the figures and source records it was calculated from
            $table->string('data_quality', 14); // COMPLETE|PARTIAL|INSUFFICIENT
            $table->text('notes')->nullable();
            $table->timestampTz('calculated_at');
            $table->timestamps();

            $table->unique(['order_id', 'kind'], 'mfg_ordvar_kind_uq');
        });

        Schema::table('mfg_production_orders', function (Blueprint $table) {
            $table->foreignId('standard_cost_id')->nullable()->constrained('mfg_standard_costs')->nullOnDelete();
            $table->decimal('act_scrap_cost', 18, 2)->default(0);
            $table->decimal('act_subcontract_cost', 18, 2)->default(0);
        });

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

            // An approved standard is what variances are measured against: it cannot be edited.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION mfg_refuse_approved_standard() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status IN ('APPROVED', 'SUPERSEDED') AND pg_trigger_depth() = 1 THEN
                        IF TG_OP = 'DELETE' THEN
                            RAISE EXCEPTION 'An approved standard cost is a record; calculate a new version instead';
                        END IF;
                        IF NEW.material_cost IS DISTINCT FROM OLD.material_cost OR NEW.labour_cost IS DISTINCT FROM OLD.labour_cost
                           OR NEW.machine_cost IS DISTINCT FROM OLD.machine_cost OR NEW.overhead_cost IS DISTINCT FROM OLD.overhead_cost
                           OR NEW.subcontract_cost IS DISTINCT FROM OLD.subcontract_cost OR NEW.unit_cost IS DISTINCT FROM OLD.unit_cost
                           OR NEW.costing_qty IS DISTINCT FROM OLD.costing_qty THEN
                            RAISE EXCEPTION 'An approved standard cost is a record; calculate a new version instead';
                        END IF;
                    END IF;
                    RETURN COALESCE(NEW, OLD);
                END; $$ LANGUAGE plpgsql;

                CREATE TRIGGER mfg_stdcost_approved BEFORE UPDATE OR DELETE ON mfg_standard_costs
                    FOR EACH ROW EXECUTE FUNCTION mfg_refuse_approved_standard();
            SQL);
        }

        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_stdcost_approved ON mfg_standard_costs');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_approved_standard()');
        }
        Schema::table('mfg_production_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('standard_cost_id');
            $t->dropColumn(['act_scrap_cost', 'act_subcontract_cost']);
        });
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
