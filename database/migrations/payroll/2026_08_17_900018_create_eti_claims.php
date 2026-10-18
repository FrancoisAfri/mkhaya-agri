<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was actually claimed for each employee, month by month, on either payroll engine.
 *
 * The incentive runs for twenty-four qualifying months, and until now the bureau worked out which
 * month it was on by measuring tenure: start date to period end. That answers a different question,
 * and it is wrong in every case where the two diverge — which is most of the interesting ones.
 *
 *   A rehire counts from the first employment, so somebody returning after two years is treated as
 *   month 30 and claims nothing, when they may have months left.
 *   An employment gap, unpaid months, or a month the employer simply did not claim, all count
 *   towards the twenty-four regardless.
 *   Somebody arriving with claims already made by a previous employer is treated as month 1.
 *   And it stopped at month 25 of *tenure* rather than of *claims*, so an employer who missed three
 *   months lost three months of benefit they were entitled to.
 *
 * SARS reconciles on what was claimed, and so does a correction. This is the ledger that answers it.
 *
 * One table for both engines, keyed by employee type and id, because the twenty-four months belong
 * to the *person*: an employee who moves from a bureau client onto the in-house payroll has not
 * earned a fresh entitlement, and two separate tables would say they had.
 *
 * Rows are written for months that claimed nothing as well as months that did, with the reason —
 * "why did this person get nothing in June" is a question a bureau has to answer long after the run
 * that decided it. Only eligible rows consume one of the twenty-four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eti_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // 'bureau' → bureau_employees, 'hr' → hr_people. Not a Laravel morph: the two engines
            // have different employee tables and a plain discriminator keeps the unique index below
            // simple and the intent obvious in a query somebody writes by hand at 2am.
            $table->string('employee_type', 10);
            $table->unsignedBigInteger('employee_id');

            // Only set on the bureau side; the in-house engine has no client company.
            $table->unsignedBigInteger('bureau_company_id')->nullable();

            // The run that produced it, on whichever engine. Nulled rather than cascaded on delete:
            // removing a run must not erase the record that a claim was made, which is exactly what
            // a reconciliation needs.
            $table->foreignId('bureau_pay_run_id')->nullable()->constrained('bureau_pay_runs')->nullOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();

            $table->string('period_key', 7);               // YYYY-MM, as SARS counts months
            $table->date('period_start');
            $table->date('period_end');
            $table->smallInteger('tax_year');

            // Which of the twenty-four this was. Null when nothing was claimed, because a month that
            // claimed nothing does not consume one.
            $table->unsignedSmallInteger('claim_month')->nullable();

            $table->boolean('eligible')->default(false);
            $table->decimal('amount', 12, 2)->default(0);
            // Restated monthly: a weekly wage has to be annualised and re-divided before it can be
            // measured against a monthly band, and the figure that chose the band is worth keeping.
            $table->decimal('monthly_remuneration', 14, 2)->default(0);
            $table->unsignedTinyInteger('age')->nullable();

            // Free text rather than a code — the reasons are read by people, and a new exclusion
            // should not need a migration.
            $table->string('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The guarantee: one claim per person per month, whatever a reprocessed run, a
            // duplicated pay run, or two engines running the same month try to do.
            $table->unique(['employee_type', 'employee_id', 'period_key'], 'eti_claims_employee_period_unique');
            $table->index(['bureau_company_id', 'tax_year']);
            $table->index(['employee_type', 'employee_id', 'eligible'], 'eti_claims_employee_eligible_index');
        });

        $this->applyTenantIsolation(['eti_claims']);
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
        Schema::dropIfExists('eti_claims');
    }
};
