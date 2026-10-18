<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-070 to WMS-073: stocktakes and cycle counts.
 *
 * A count never changes stock by itself. It records what people found -- every entry, by every
 * counter, in every round -- against what the ledger expected at the moment of counting, and only
 * an approved variance becomes a stock adjustment, through the same adjustment workflow as any
 * other correction. The original counts, the recounts, the investigation and the resulting
 * movement all stay.
 *
 * "Expected at the moment of counting" is the point of the design: a count that runs while the
 * warehouse keeps working compares each entry with the snapshot plus the movements that touched
 * that bin since, rather than with a snapshot that is already wrong. Where a business prefers to
 * stop the floor instead, the bins are frozen and the ledger refuses movements in them.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_count_plans', 'wms_counts', 'wms_count_lines', 'wms_count_entries'];

    public function up(): void
    {
        Schema::create('wms_count_plans', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('name', 120);
            $t->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $t->string('frequency', 12)->default('ONCE'); // ONCE|DAILY|WEEKLY|MONTHLY|QUARTERLY|ANNUAL|EVENT
            $t->string('scope', 12)->default('WAREHOUSE'); // WAREHOUSE|ZONE|BINS|ITEMS|CATEGORY|LOTS|ABC|VALUE|RISK
            $t->jsonb('scope_ids')->nullable();
            $t->string('abc_class', 1)->nullable();
            $t->decimal('min_value', 18, 2)->nullable();
            $t->unsignedSmallInteger('max_lines')->nullable();
            $t->boolean('blind')->default(true);
            $t->boolean('freeze')->default(false);
            $t->decimal('recount_qty', 18, 4)->nullable();
            $t->decimal('recount_pct', 8, 4)->nullable();
            $t->decimal('recount_value', 18, 2)->nullable();
            $t->unsignedSmallInteger('deadline_days')->default(2);
            $t->jsonb('team')->nullable(); // user ids
            $t->timestampTz('next_run_at')->nullable();
            $t->timestampTz('last_run_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });

        Schema::create('wms_counts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('plan_id')->nullable()->constrained('wms_count_plans')->nullOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->string('reason', 255)->nullable();
            $t->string('trigger', 16)->default('MANUAL'); // MANUAL|PLAN|SHORT_PICK|EMPTY_PICK|NEGATIVE|ADJUSTMENT
            $t->string('status', 12)->default('OPEN'); // OPEN|COUNTING|REVIEW|POSTED|CANCELLED
            $t->boolean('blind')->default(true);
            $t->boolean('freeze')->default(false);
            $t->decimal('recount_qty', 18, 4)->nullable();
            $t->decimal('recount_pct', 8, 4)->nullable();
            $t->decimal('recount_value', 18, 2)->nullable();
            $t->jsonb('team')->nullable();
            $t->timestampTz('snapshot_at');
            $t->timestampTz('deadline_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_count_number_uniq');
        });

        Schema::create('wms_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('count_id')->constrained('wms_counts')->cascadeOnDelete();
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->foreignId('item_id')->nullable()->constrained('fin_stock_items')->nullOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $t->decimal('snapshot_qty', 18, 4)->default(0);
            $t->decimal('expected_qty', 18, 4)->nullable();   // snapshot + movements up to the accepted count
            $t->decimal('counted_qty', 18, 4)->nullable();    // the accepted count
            $t->decimal('variance_qty', 18, 4)->nullable();
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->decimal('variance_value', 18, 2)->nullable();
            $t->unsignedSmallInteger('round')->default(1);
            $t->string('status', 12)->default('OPEN'); // OPEN|COUNTED|RECOUNT|MATCHED|VARIANCE|ADJUSTED|ACCEPTED
            $t->boolean('unexpected')->default(false);
            $t->boolean('found_empty')->default(false);
            $t->jsonb('causes')->nullable();
            $t->text('investigation')->nullable();
            $t->foreignId('adjustment_id')->nullable()->constrained('wms_stock_adjustments')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['count_id', 'status'], 'wms_count_line_status_idx');
        });

        // Every entry, never overwritten: the first count, the recount and the verification each stay.
        Schema::create('wms_count_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('count_line_id')->constrained('wms_count_lines')->cascadeOnDelete();
            $t->unsignedSmallInteger('round');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->jsonb('serials')->nullable();
            $t->string('client_ref', 64)->nullable(); // offline / retry idempotency
            $t->boolean('duplicate')->default(false);
            $t->decimal('expected_at_entry', 18, 4)->nullable();
            $t->decimal('moved_since_snapshot', 18, 4)->default(0);
            $t->timestampTz('counted_at');
            $t->timestampTz('created_at')->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX wms_count_entry_client_ref ON wms_count_entries (company_id, client_ref) WHERE client_ref IS NOT NULL');

        Schema::table('fin_stock_bins', function (Blueprint $t) {
            $t->foreignId('frozen_by_count_id')->nullable()->constrained('wms_counts')->nullOnDelete();
        });
        Schema::table('wms_item_profiles', function (Blueprint $t) {
            $t->string('abc_class', 1)->nullable();
            $t->timestampTz('abc_computed_at')->nullable();
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
            DB::unprepared('CREATE TRIGGER wms_count_entries_append_only BEFORE UPDATE OR DELETE ON wms_count_entries '
                .'FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION wms_refuse_history_edit()');
        }
    }

    public function down(): void
    {
        Schema::table('wms_item_profiles', fn (Blueprint $t) => $t->dropColumn(['abc_class', 'abc_computed_at']));
        Schema::table('fin_stock_bins', fn (Blueprint $t) => $t->dropConstrainedForeignId('frozen_by_count_id'));
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
