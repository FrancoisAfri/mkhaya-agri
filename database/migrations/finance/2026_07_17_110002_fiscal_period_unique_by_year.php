<?php

use App\Shared\Database\OptionalStatement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With dynamic fiscal years, a period's grouping key is `fiscal_year_id`, not the old `fiscal_year`
 * (calendar) int — two years that share a calendar year (e.g. after a company changes its fiscal
 * start month) can legitimately reuse the same `(fiscal_year, period_no)`. Swap the uniqueness onto
 * `(company_id, fiscal_year_id, period_no)` so period generation stays idempotent without blocking
 * legitimate calendars.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Drop the legacy unique (company_id, fiscal_year, period_no) if present.
         *
         * Guarded rather than caught: "the constraint name differs or it is already gone" is the
         * expected case, and an unguarded failure aborts the transaction — so the CREATE INDEX
         * below would fail too, and the migration would die on precisely the databases this was
         * written to tolerate.
         */
        OptionalStatement::attempt(
            'drop the legacy fiscal-period unique constraint',
            fn () => Schema::table('fin_fiscal_periods', function (Blueprint $table) {
                $table->dropUnique('fin_fiscal_periods_company_id_fiscal_year_period_no_unique');
            }),
        );

        // Add the new unique. Partial index (WHERE fiscal_year_id IS NOT NULL) so any legacy rows
        // that were never backfilled don't trip it.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS fin_periods_year_pno_unique
            ON fin_fiscal_periods (company_id, fiscal_year_id, period_no)
            WHERE fiscal_year_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fin_periods_year_pno_unique');
    }
};
