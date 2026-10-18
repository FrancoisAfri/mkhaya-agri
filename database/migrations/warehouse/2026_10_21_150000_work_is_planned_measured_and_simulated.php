<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-130 to WMS-135: tasks, labour, picking and slotting advice, performance and simulation.
 *
 * The tasks themselves stay where they are -- put-away, replenishment and pick tasks, counts,
 * orders waiting to be packed or dispatched -- and the board reads them all. What it adds is one
 * record of who a task is given to and when it is due, whatever its kind, kept as history so a
 * reassignment never erases the first assignment. Slotting changes are proposals that are simulated
 * and approved before they become work; simulations are kept with their inputs and seed so their
 * results can be reproduced.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_task_assignments', 'wms_labour_standards', 'wms_slotting_proposals', 'wms_simulations'];

    public function up(): void
    {
        Schema::create('wms_task_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('task_type', 12); // RECEIVE|PUTAWAY|REPLENISH|PICK|PACK|COUNT|DISPATCH
            $t->string('task_ref', 64);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('priority', 8)->nullable();
            $t->timestampTz('due_at')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_current')->default(true);
            $t->string('note', 255)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['company_id', 'task_type', 'task_ref', 'is_current'], 'wms_task_assign_lookup');
        });

        Schema::create('wms_labour_standards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->cascadeOnDelete();
            $t->string('activity', 12);
            $t->decimal('minutes_per_task', 10, 2)->default(0);
            $t->decimal('minutes_per_unit', 10, 4)->default(0);
            $t->string('source', 10)->default('SET'); // SET|MEASURED
            $t->unsignedInteger('sample_size')->default(0);
            $t->timestampsTz();
            $t->unique(['company_id', 'warehouse_id', 'activity'], 'wms_labour_std_uniq');
        });

        Schema::create('wms_slotting_proposals', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $t->string('status', 10)->default('DRAFT'); // DRAFT|SIMULATED|APPROVED|APPLIED|REJECTED
            $t->jsonb('moves');
            $t->jsonb('simulation')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE wms_slotting_proposals ADD CONSTRAINT wms_slotting_four_eyes CHECK (approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by)');

        Schema::create('wms_simulations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
            $t->string('kind', 14); // STAFFING|DEMAND|LAYOUT|REPLENISHMENT
            $t->string('name', 160)->nullable();
            $t->jsonb('inputs');
            $t->jsonb('results');
            $t->unsignedInteger('trials');
            $t->unsignedBigInteger('seed');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
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
