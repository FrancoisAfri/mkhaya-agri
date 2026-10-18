<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employment milestones — the moments a payroll administrator has to be told about.
 *
 * The requirement arrived with its own disagreement in it: the minutes said one month, the follow-up
 * said one year. Which is the point. A probation review is a month, a fixed-term check is six, an
 * incentive runs to twenty-four, and different clients count different ones — so the milestones are
 * configured per company rather than argued about once and then baked in.
 *
 * Two tables, because rules and history are not the same thing:
 *
 *   employment_milestone_rules   — which milestones this company cares about, and how loudly.
 *   employment_milestones        — the ones that have actually come due, and whether anybody acted.
 *
 * The second exists so a notice is raised once. A daily scan with no memory would either send the
 * same twelve-month reminder every morning until somebody dismissed it — which trains people to
 * filter payroll mail — or fire only on the exact anniversary date and lose it entirely to a weekend,
 * a holiday, or a server that was down. Claiming the row first makes the scan safe to run as often
 * as we like, and safe to re-run after a failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_milestone_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // Null means the tenant's default, applied to every client. A row naming a client
            // overrides it, because a bureau's clients do not all run the same review cycle.
            $table->unsignedBigInteger('bureau_company_id')->nullable();

            $table->unsignedSmallInteger('months');
            $table->string('label')->nullable();

            // Who it is for. Null means everybody; otherwise the employment categories it applies
            // to, so "24 months" can be an intern-only incentive check rather than a long-service
            // note sent to the whole payroll.
            $table->json('categories')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('notify_email')->default(false);   // in-app always; email on request
            $table->boolean('notify_employee')->default(false); // otherwise administrators only

            // How far ahead to raise it. A twenty-four month incentive expiry is no use on the day
            // it expires — the run it affects is prepared before then.
            $table->unsignedSmallInteger('lead_days')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        // One rule per scope per milestone. Expressed over COALESCE rather than the plain columns
        // because Postgres treats NULLs as distinct in a unique index — and NULL is exactly what a
        // tenant-wide default carries, so the ordinary index would have policed every client's rules
        // and none of the defaults.
        $this->uniqueOverNulls();

        Schema::create('employment_milestones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // Same discriminator as the incentive ledger: 'bureau' → bureau_employees, 'hr' →
            // hr_people. The two engines have different employee tables.
            $table->string('employee_type', 10);
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('bureau_company_id')->nullable();

            $table->foreignId('rule_id')->nullable()->constrained('employment_milestone_rules')->nullOnDelete();
            $table->unsignedSmallInteger('months');

            // Everything the notice has to state, captured as it was on the day it was raised. Read
            // back rather than recomputed: an employee whose start date is corrected afterwards must
            // not silently rewrite the history of what payroll was told.
            $table->date('start_date')->nullable();
            $table->date('due_on');
            $table->string('employee_name')->nullable();
            $table->string('employee_number')->nullable();
            $table->string('employment_category', 40)->nullable();

            // The incentive position at the time, which is the whole reason a bureau reads these.
            $table->boolean('eti_tracked')->default(false);
            $table->unsignedSmallInteger('eti_months_claimed')->nullable();
            $table->unsignedSmallInteger('eti_months_remaining')->nullable();

            $table->string('reason')->nullable();

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The guarantee: one notice per employee per milestone, however often the scan runs.
            $table->unique(['employee_type', 'employee_id', 'months'], 'employment_milestones_unique');
            $table->index(['company_id', 'acknowledged_at']);
            $table->index(['bureau_company_id', 'due_on']);
        });

        $this->applyTenantIsolation(['employment_milestone_rules', 'employment_milestones']);
    }

    private function uniqueOverNulls(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX employment_milestone_rules_unique ON employment_milestone_rules '
            . '(COALESCE(company_id, 0), COALESCE(bureau_company_id, 0), months)',
        );
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
        Schema::dropIfExists('employment_milestones');
        Schema::dropIfExists('employment_milestone_rules');
    }
};
