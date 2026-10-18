<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who approves a client's payroll, and who is actually on the run.
 *
 * Two gaps that show up the moment a bureau runs a real month.
 *
 * Approval was one step with one rule — not the person who calculated it. That is a control, but it
 * is not an approval chain: a bureau whose client wants the HR manager to sign the figures and then
 * the finance director to release the money had nowhere to say so. The steps are configured now, in
 * order, each naming who may sign it.
 *
 * The roster is the other half. `process()` swept in every active employee on the run's cycle, which
 * is right for a monthly salary run and wrong for everything else: a bonus run for three people
 * would have paid the whole company. A run now carries its own list. A regular run still starts with
 * everyone on that cycle, because that is what a regular run means; an off-cycle, bonus or
 * termination run starts empty and the officer says who it is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bureau_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // Null means the tenant's default chain, used by every client that has not defined its
            // own. A client with steps of its own replaces it rather than adding to it, so a client
            // who deliberately removed a step does not get it back from the default.
            $table->unsignedBigInteger('bureau_company_id')->nullable();

            $table->unsignedSmallInteger('sequence');
            $table->string('name');

            // Who may sign this step: anyone holding a role, or one named person.
            $table->string('approver_type', 20)->default('role');   // role | user
            $table->string('role_name')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            // A step that may be signed by whoever calculated the run. Off by default: the whole
            // point of a chain is that somebody else looks.
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('instructions')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'bureau_company_id', 'sequence'], 'bureau_approval_steps_order');
        });

        // Every decision on every step, kept whether it approved or refused — "who signed this and
        // when" has to survive the run being reopened and signed again.
        Schema::create('bureau_pay_run_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bureau_pay_run_id')->constrained('bureau_pay_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('bureau_company_id')->nullable();

            $table->foreignId('step_id')->nullable()->constrained('bureau_approval_steps')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('step_name');

            $table->string('decision', 20);                  // approved | rejected
            $table->text('comment')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // Which attempt this belongs to. A reopened run starts a fresh round rather than
            // appearing to already hold last round's signatures.
            $table->unsignedSmallInteger('round')->default(1);

            $table->timestamps();

            $table->index(['bureau_pay_run_id', 'round', 'sequence'], 'bureau_run_approvals_order');
        });

        Schema::table('bureau_pay_runs', function (Blueprint $table) {
            // How far up the chain the run has got. Null once every step has signed.
            $table->unsignedSmallInteger('current_step')->nullable()->after('status');
            $table->unsignedSmallInteger('approval_round')->default(1)->after('current_step');
        });

        // The roster: who this particular run pays.
        Schema::create('bureau_pay_run_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bureau_pay_run_id')->constrained('bureau_pay_runs')->cascadeOnDelete();
            $table->foreignId('bureau_employee_id')->constrained('bureau_employees')->cascadeOnDelete();

            // How they got here, so an officer can tell the automatic ones from the ones they chose.
            $table->string('added_via', 20)->default('manual');   // auto | manual | input
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The guarantee: one line per person per run, so adding somebody twice cannot pay them
            // twice.
            $table->unique(['bureau_pay_run_id', 'bureau_employee_id'], 'bureau_run_employee_unique');
        });

        $this->applyTenantIsolation([
            'bureau_approval_steps', 'bureau_pay_run_approvals', 'bureau_pay_run_employees',
        ]);
    }

    private function applyTenantIsolation(array $tables): void
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

    public function down(): void
    {
        Schema::dropIfExists('bureau_pay_run_employees');
        Schema::dropIfExists('bureau_pay_run_approvals');
        Schema::dropIfExists('bureau_approval_steps');
        Schema::table('bureau_pay_runs', fn (Blueprint $t) => $t->dropColumn(['current_step', 'approval_round']));
    }
};
