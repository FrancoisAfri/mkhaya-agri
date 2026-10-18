<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-016 -- labour and workforce planning.
 *
 * What an operation needs from the person doing it is stated as a requirement on the routing
 * operation, pointing at what HR, the learning system and SHEQ already keep: a skill at a level
 * (employee_skills), a certification that has not expired (employee_certifications), or a SHEQ
 * competency that is current (sheq_competency_records). Nothing is copied; eligibility is read
 * from where it lives.
 *
 * The roster says who works which shift at which work centre on which day -- planned labour.
 * Actual labour is the shop floor's time logs. Payroll is never written to: manufacturing offers
 * hours as costing input and payroll stays the record of pay.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_operation_requirements', 'mfg_roster'];

    public function up(): void
    {
        Schema::create('mfg_operation_requirements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // On a routing operation, or on a whole work centre (anyone working there needs it).
            $table->foreignId('routing_operation_id')->nullable()->constrained('mfg_routing_operations')->cascadeOnDelete();
            $table->foreignId('work_centre_id')->nullable()->constrained('mfg_work_centres')->cascadeOnDelete();
            $table->string('kind', 14);                         // SKILL|CERTIFICATION|COMPETENCY
            $table->unsignedBigInteger('reference_id');         // skills.id | lms_certifications.id | sheq_competencies.id
            $table->unsignedSmallInteger('min_level')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();
        });

        Schema::create('mfg_roster', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('mfg_shifts')->cascadeOnDelete();
            $table->foreignId('work_centre_id')->nullable()->constrained('mfg_work_centres')->nullOnDelete();
            $table->date('work_date');
            $table->decimal('overtime_minutes', 8, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'work_date', 'shift_id'], 'mfg_roster_slot_uq');
            $table->index(['company_id', 'work_date'], 'mfg_roster_date_idx');
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
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
