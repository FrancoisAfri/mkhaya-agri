<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-007 -- shop-floor execution.
 *
 * What an operator does at a workstation becomes a record: who was given the job, every start,
 * pause and resume as a time log (so labour and machine time are measured, not remembered),
 * downtime against a reason code, notes, and escalations to a supervisor that climb if nobody
 * answers. Every floor action carries a client key and is applied once, however many times a
 * tablet on bad Wi-Fi sends it.
 */
return new class extends Migration
{
    private const TABLES = [
        'mfg_operation_time_logs', 'mfg_downtime_reasons', 'mfg_downtime_events', 'mfg_floor_escalations', 'mfg_floor_submissions',
    ];

    public function up(): void
    {
        Schema::table('mfg_production_order_operations', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained('mfg_workstations')->nullOnDelete();
            $table->timestampTz('assigned_at')->nullable();
        });

        Schema::table('mfg_factory_policies', function (Blueprint $table) {
            // An escalation nobody acknowledges climbs a level after this long.
            $table->unsignedInteger('escalate_after_minutes')->default(30);
        });

        Schema::create('mfg_operation_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_operation_id')->constrained('mfg_production_order_operations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->decimal('minutes', 12, 2)->nullable();
            $table->string('ended_by_action', 12)->nullable(); // PAUSE|COMPLETE
            $table->timestamps();

            $table->index(['order_operation_id', 'ended_at'], 'mfg_optl_open_idx');
        });

        Schema::create('mfg_downtime_reasons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            // BREAKDOWN|CHANGEOVER|SETUP|MATERIAL|QUALITY|LABOUR|PLANNED_MAINTENANCE|UTILITIES|OTHER
            $table->string('category', 24);
            // Planned downtime (a scheduled service, a break) comes out of planned production time
            // for OEE; unplanned downtime counts against availability.
            $table->boolean('is_planned')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'mfg_dtr_code_uq');
        });

        Schema::create('mfg_downtime_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories');
            $table->foreignId('work_centre_id')->constrained('mfg_work_centres');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->foreignId('order_operation_id')->nullable()->constrained('mfg_production_order_operations')->nullOnDelete();
            $table->foreignId('reason_id')->constrained('mfg_downtime_reasons');
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->decimal('minutes', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'work_centre_id', 'started_at'], 'mfg_dte_centre_idx');
        });

        Schema::create('mfg_floor_escalations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories');
            $table->foreignId('order_id')->nullable()->constrained('mfg_production_orders')->nullOnDelete();
            $table->foreignId('order_operation_id')->nullable()->constrained('mfg_production_order_operations')->nullOnDelete();
            $table->foreignId('work_centre_id')->nullable()->constrained('mfg_work_centres')->nullOnDelete();
            $table->string('kind', 16); // MATERIAL|MACHINE|QUALITY|SAFETY|INSTRUCTION|OTHER
            $table->text('message');
            $table->string('status', 14)->default('OPEN'); // OPEN|ACKNOWLEDGED|RESOLVED
            $table->unsignedTinyInteger('level')->default(1);
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('raised_at');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('escalated_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'mfg_fesc_status_idx');
        });

        Schema::create('mfg_floor_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->jsonb('response');
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'mfg_fsub_key_uq');
        });

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('mfg_factory_policies', fn (Blueprint $t) => $t->dropColumn('escalate_after_minutes'));
        Schema::table('mfg_production_order_operations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assigned_to');
            $t->dropConstrainedForeignId('workstation_id');
            $t->dropColumn('assigned_at');
        });
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
