<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The half of budgeting that happens before anybody spends anything.
 *
 * The module could hold an allocation and account against it precisely, and had nothing to say
 * about the two questions finance actually asks first: *when* is this money expected to go, and
 * *what did the budget look like before somebody changed it*.
 *
 *   budget_phasings   the allocation spread across the year's periods. Without it, "budget vs
 *                     actual" in month four compares four months of spend against twelve months of
 *                     budget, which makes every budget look healthy until the day it is not.
 *   budget_revisions  what the allocation was, each time it moved, and why. The adjustment rows say
 *                     what was requested; these say what the budget became — and those differ the
 *                     moment a transfer credits a budget that requested nothing.
 *
 * Both are additive. A tenant that phases nothing gets the behaviour it has now, with the phased
 * comparison simply reporting that there is no plan to compare against.
 */
return new class extends Migration
{
    private const TABLES = ['budget_phasings', 'budget_revisions'];

    public function up(): void
    {
        Schema::create('budget_phasings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('budget_id')->index();
            $t->unsignedBigInteger('budget_period_id')->index();
            $t->decimal('amount', 18, 2)->default(0);
            $t->text('note')->nullable();
            $t->timestamps();

            // One planned figure per period per budget. Two rows for the same month is two plans,
            // and every report built on them would have to pick one.
            $t->unique(['budget_id', 'budget_period_id'], 'budget_phasings_budget_period_unique');
        });

        Schema::create('budget_revisions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('budget_id')->index();
            $t->unsignedInteger('version')->default(1);
            $t->decimal('previous_amount', 18, 2)->nullable();
            $t->decimal('amount', 18, 2);
            $t->string('reason_type', 30);              // created | approved | adjustment | transfer_in | carry_forward
            $t->unsignedBigInteger('source_id')->nullable();  // the adjustment, when there was one
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'budget_id', 'version']);
        });

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
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
