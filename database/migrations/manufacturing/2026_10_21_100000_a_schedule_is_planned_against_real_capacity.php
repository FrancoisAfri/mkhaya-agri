<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-014 -- production scheduling against the factory's real constraints.
 *
 * Each operation gets a scheduled start, finish and work centre (an alternate where that finishes
 * sooner), and a baseline finish fixed the first time it is scheduled, so schedule adherence is
 * measured against what was promised rather than against the latest reshuffle. Orders get the
 * earliest start a planner has set and their scheduled finish. Every run is kept with its
 * exceptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfg_production_order_operations', function (Blueprint $table) {
            $table->date('sched_start')->nullable();
            $table->date('sched_end')->nullable();
            $table->foreignId('sched_work_centre_id')->nullable()->constrained('mfg_work_centres')->nullOnDelete();
            $table->date('baseline_end')->nullable();
        });

        Schema::table('mfg_production_orders', function (Blueprint $table) {
            $table->date('sched_earliest_start')->nullable();   // a planner's "not before"
            $table->date('sched_finish')->nullable();
        });

        Schema::create('mfg_schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('rule', 20);
            $table->unsignedSmallInteger('horizon_days');
            $table->jsonb('summary')->nullable();
            $table->jsonb('exceptions')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE mfg_schedule_runs ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE mfg_schedule_runs ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE mfg_schedule_runs FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON mfg_schedule_runs');
            DB::statement("CREATE POLICY tenant_isolation ON mfg_schedule_runs USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_schedule_runs');
        Schema::table('mfg_production_orders', fn (Blueprint $t) => $t->dropColumn(['sched_earliest_start', 'sched_finish']));
        Schema::table('mfg_production_order_operations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sched_work_centre_id');
            $t->dropColumn(['sched_start', 'sched_end', 'baseline_end']);
        });
    }
};
