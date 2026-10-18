<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stable key other modules can post against exactly once.
 *
 * The budget could already be told about spending, and Procurement told it. Nothing else did —
 * payroll, fleet, claims, contracts all move real money through modules that had no way to say so,
 * which is why "available" flattered every budget in the product.
 *
 * Opening that door needs an idempotency key first. A payroll run that is approved, corrected and
 * re-approved must not consume the budget twice, and a retried job must not either. `reference` is
 * that key: a caller supplying one gets at-most-once posting enforced by the database rather than
 * by every caller remembering to check.
 *
 * Nullable and partially unique on purpose. Procurement posts several times against one purchase
 * order — partial deliveries are separate, legitimate consumptions — so callers that do not supply
 * a reference are unconstrained, exactly as they are today.
 */
return new class extends Migration
{
    private const TABLES = ['budget_consumptions', 'budget_commitments', 'budget_reservations'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'reference')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->string('reference', 120)->nullable()->after('source_id');
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("CREATE UNIQUE INDEX {$table}_reference_unique ON {$table} (company_id, reference) WHERE reference IS NOT NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP INDEX IF EXISTS {$table}_reference_unique");
            }

            if (Schema::hasColumn($table, 'reference')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('reference'));
            }
        }
    }
};
