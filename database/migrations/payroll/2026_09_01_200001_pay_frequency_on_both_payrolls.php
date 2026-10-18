<?php

use App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine;
use App\Shared\Database\OptionalStatement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One company, several pay frequencies — and a guard against paying anybody twice for it.
 *
 * ── What was missing ────────────────────────────────────────────────────────
 *
 * `payroll_periods.frequency` existed, so the direct payroll could describe a weekly period, but
 * no employee carried a frequency: every run therefore swept up everybody. The bureau had the
 * mirror-image gap — `bureau_employees.pay_frequency` existed and nothing read it, and
 * `bureau_pay_runs.frequency` recorded an intention no selection honoured.
 *
 * So a weekly run paid monthly staff a month early, and the schema had no opinion about it.
 *
 * ── The part that matters more than the columns ─────────────────────────────
 *
 * `payslips` was unique on (payroll_run_id, employee_id): an employee could not appear twice in
 * one run, and could appear in as many runs as somebody cared to create over the same dates. With
 * several frequencies live at once that stops being theoretical — the weekly and the monthly run
 * both exist in the same month, and an employee on the wrong frequency lands in both.
 *
 * Postgres can state the real rule directly. An exclusion constraint refuses a second payslip for
 * the same employee whose period *overlaps* an existing one, which is the actual invariant: not
 * "not the same period" but "not overlapping periods". A service check would be racy; this one is
 * enforced by the database whatever writes to it, including a queued job and a bureau import.
 */
return new class extends Migration
{
    /**
     * Every existing employee and run inherits what their company actually pays.
     *
     * The column default is monthly, and taking that as the answer for existing rows would be a
     * silent demotion: a company whose PayrollSetting says weekly has been paying weekly all along,
     * and stamping its people monthly makes every one of them ineligible for the weekly run they
     * belong on. The run comes back empty, which reads as this migration having broken payroll —
     * and the temptation then is to widen the filter rather than fix the data.
     *
     * Read from payroll_settings per company, since that is where the truth has lived until now.
     * Bureau rows are left alone: bureau_employees.pay_frequency already existed and is already
     * populated per client.
     */
    private function backfillFromCompanySettings(): void
    {
        $settings = DB::table('payroll_settings')
            ->whereNotNull('company_id')
            ->get(['company_id', 'pay_frequency']);

        foreach ($settings as $setting) {
            $frequency = PayrollTaxEngine::canonicalFrequency($setting->pay_frequency);

            // An unreadable stored value is left on the default rather than guessed at, and said
            // out loud — the payslips it would produce look entirely ordinary.
            if ($frequency === null) {
                logger()->warning('pay frequency backfill skipped a company with an unreadable setting', [
                    'company_id' => $setting->company_id, 'pay_frequency' => $setting->pay_frequency,
                ]);

                continue;
            }

            if ($frequency === 'monthly') {
                continue;   // already the column default
            }

            DB::table('hr_people')->where('company_id', $setting->company_id)
                ->update(['pay_frequency' => $frequency]);

            DB::table('payroll_runs')->where('company_id', $setting->company_id)
                ->update(['frequency' => $frequency]);
        }
    }

    public function up(): void
    {
        Schema::table('hr_people', function (Blueprint $table) {
            // Monthly for the column default because it is the overwhelmingly common case and a
            // NULL here would make "unset" and "monthly" indistinguishable. It is not, however, the
            // right answer for every existing row — see the backfill below.
            $table->string('pay_frequency', 20)->default('monthly')->after('id');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('frequency', 20)->default('monthly')->after('period_end');
            // Every list, report and payment file filters on this pair.
            $table->index(['company_id', 'frequency', 'status'], 'payroll_runs_freq_idx');
        });

        $this->backfillFromCompanySettings();

        Schema::table('bureau_pay_run_employees', function (Blueprint $table) {
            // The bureau side had no unique at all, so the same employee could be added to one run
            // twice and be paid twice out of a single file.
            $table->unique(['bureau_pay_run_id', 'bureau_employee_id'], 'bureau_run_employee_uq');
        });

        if (DB::getDriverName() === 'pgsql') {
            /*
             * btree_gist lets an exclusion constraint mix equality on a bigint with overlap on a
             * range. It needs rights the application user may not have, so it is optional — but it
             * has to be optional *safely*.
             *
             * A plain try/catch here is what lost this entire migration on a live install. A failed
             * statement aborts the Postgres transaction; catching the exception does not clear
             * that, and the COMMIT which follows silently behaves as ROLLBACK. So the columns above
             * were discarded, and Laravel recorded the migration as run immediately afterwards —
             * `migrate:status` said Ran, `migrate` said nothing was pending, and payroll died on
             * `hr_people.pay_frequency does not exist`.
             *
             * OptionalStatement runs each step in a savepoint, so a refusal costs only that step.
             */
            $extension = OptionalStatement::attempt(
                'create the btree_gist extension',
                fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist'),
                'the payslip overlap guard cannot be created without it',
            );

            if ($extension) {
                OptionalStatement::attempt(
                    'add the payslips overlapping-period exclusion constraint',
                    fn () => DB::statement("
                        ALTER TABLE payslips
                        ADD CONSTRAINT payslips_no_overlapping_period
                        EXCLUDE USING gist (
                            employee_id WITH =,
                            daterange(period_start, period_end, '[]') WITH &&
                        )
                        WHERE (deleted_at IS NULL)
                    "),
                    'an employee could be paid twice for the same dates across two runs',
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payslips DROP CONSTRAINT IF EXISTS payslips_no_overlapping_period');
        }

        Schema::table('bureau_pay_run_employees', function (Blueprint $table) {
            $table->dropUnique('bureau_run_employee_uq');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropIndex('payroll_runs_freq_idx');
            $table->dropColumn('frequency');
        });

        Schema::table('hr_people', function (Blueprint $table) {
            $table->dropColumn('pay_frequency');
        });
    }
};
