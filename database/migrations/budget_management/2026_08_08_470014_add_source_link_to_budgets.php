<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a budget be the mirror of a record another module owns.
 *
 * The problem this solves is that the product shipped two budgets. `fin_budgets` in Finance ›
 * Accounting holds an account-level plan for a fiscal year and reports budget-versus-actual off the
 * general ledger. This module holds the allocation people actually spend against — reservations,
 * commitments, approvals, blocking rules. A company running both had two numbers and no rule about
 * which one was true.
 *
 * The rule is now: **this module is the record.** Accounting keeps its own screens and its own
 * account-level plan — a company with only the accounting module is unaffected — but when both are
 * live, each expense account in an accounting budget is mirrored into a budget here, and this is
 * where control happens.
 *
 *   source_module  which module owns the upstream record ('finance_accounting')
 *   source_id      the upstream record's id (the fin_budget)
 *   source_ref     the row within it (the account), because one accounting budget covers many
 *   is_managed     "another module maintains this" — the UI stops offering to hand-edit what the
 *                  next sync will overwrite, which is the difference between an integration and a
 *                  trap
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $t) {
            $t->string('source_module', 40)->nullable()->after('created_by');
            $t->unsignedBigInteger('source_id')->nullable()->after('source_module');
            $t->string('source_ref', 60)->nullable()->after('source_id');
            $t->boolean('is_managed')->default(false)->after('source_ref');
        });

        Schema::table('budgets', function (Blueprint $t) {
            $t->index(['company_id', 'source_module', 'source_id'], 'budgets_source_index');
        });

        // One mirror per upstream row. Without this a re-sync that raced itself would double a
        // department's allocation, and the second copy would look exactly as legitimate as the first.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX budgets_source_unique
                ON budgets (company_id, source_module, source_id, source_ref)
                WHERE deleted_at IS NULL AND source_module IS NOT NULL
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS budgets_source_unique');
        }

        Schema::table('budgets', function (Blueprint $t) {
            $t->dropIndex('budgets_source_index');
            $t->dropColumn(['source_module', 'source_id', 'source_ref', 'is_managed']);
        });
    }
};
