<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-013 -- machine maintenance and OEE, on top of the asset register.
 *
 * The machine stays an asset and its repairs stay asset work orders, with their parts and labour
 * (the asset module already does that well). What manufacturing adds is what only the plant knows:
 * preventive rules that fire on calendar time, on the hours the machine actually ran or on a run of
 * breakdowns; fault codes on breakdowns; a hold that stops the floor using a machine until it is
 * returned to service; and the definitions OEE is calculated from, stated rather than assumed.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_machine_profiles', 'mfg_fault_codes', 'mfg_pm_rules', 'mfg_pm_work_orders', 'mfg_oee_settings'];

    public function up(): void
    {
        Schema::create('mfg_machine_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('asset_id');
            $table->string('hold_status', 12)->default('NONE');    // NONE|MAINTENANCE|SAFETY
            $table->text('hold_reason')->nullable();
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('held_at')->nullable();
            $table->boolean('requires_return_to_service')->default(true);
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->text('release_notes')->nullable();
            // Hours run before the plant started recording, so usage rules start from the real meter.
            $table->decimal('run_hours_baseline', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'asset_id'], 'mfg_machine_asset_uq');
        });

        Schema::create('mfg_fault_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 24)->nullable(); // MECHANICAL|ELECTRICAL|HYDRAULIC|PNEUMATIC|CONTROL|TOOLING|OTHER
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'mfg_fault_code_uq');
        });

        Schema::create('mfg_pm_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('asset_id');
            $table->string('title');
            $table->string('kind', 10); // TIME|USAGE|CONDITION
            $table->unsignedInteger('interval_days')->nullable();
            $table->decimal('interval_run_hours', 12, 2)->nullable();
            $table->unsignedInteger('breakdowns_threshold')->nullable();  // CONDITION: this many breakdowns ...
            $table->unsignedInteger('window_days')->nullable();          // ... within this many days
            $table->text('tasks')->nullable();                             // the checklist, one per line
            $table->string('work_order_type', 12)->default('preventive');
            $table->string('priority', 10)->default('medium');
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_triggered_at')->nullable();
            $table->decimal('run_hours_at_last', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mfg_pm_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('mfg_pm_rules')->cascadeOnDelete();
            $table->unsignedBigInteger('work_order_id');
            $table->string('trigger', 120);   // why it was raised: "90 days", "250.0 run hours", "3 breakdowns in 30 days"
            $table->timestamps();
        });

        Schema::create('mfg_oee_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('work_centre_id')->constrained('mfg_work_centres')->cascadeOnDelete();
            // The definitions OEE is calculated from, configured rather than assumed.
            $table->decimal('ideal_cycle_seconds', 12, 3)->nullable();     // per good-or-bad unit at full speed
            $table->boolean('ideal_from_routing')->default(false);         // or the routing's run time, per product
            $table->boolean('exclude_planned_downtime')->default(true);    // planned stops come out of planned time
            $table->string('good_output', 24)->default('COMPLETED');       // COMPLETED: completed qty is good; total adds rejected + scrapped
            $table->timestamps();

            $table->unique(['company_id', 'work_centre_id'], 'mfg_oee_centre_uq');
        });

        Schema::table('mfg_downtime_events', function (Blueprint $table) {
            $table->foreignId('fault_code_id')->nullable()->constrained('mfg_fault_codes')->nullOnDelete();
            $table->unsignedBigInteger('work_order_id')->nullable();
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
        }

        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('mfg_downtime_events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fault_code_id');
            $t->dropColumn('work_order_id');
        });
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
