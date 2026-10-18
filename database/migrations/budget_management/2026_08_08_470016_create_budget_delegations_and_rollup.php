<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two gaps that show up the moment a real company uses this.
 *
 * **Delegation.** An approver goes on leave and the queue stops. Escalation as built re-notifies
 * the same person who is not there, which is a reminder nobody reads. Claims, Fleet, Governance and
 * Leave all have delegation tables; budget approvals were the outlier, and budgets are the one
 * queue where stalling costs a supplier a payment.
 *
 * **Rollup.** Budgets were flat. A division head with fourteen cost centres could see fourteen
 * numbers and never the one they are actually accountable for. `parent_id` makes the register a
 * tree, and the consolidated position is computed from it — a parent's own allocation plus
 * everything beneath it.
 *
 * Depth is deliberately not constrained by the schema; the service refuses a cycle instead, because
 * "a division containing regions containing branches" is three levels in one company and two in the
 * next, and a limit in the database is a limit nobody can lift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_approval_delegations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('from_user_id');   // the approver who is away
            $t->unsignedBigInteger('to_user_id');     // whoever holds it while they are
            $t->date('starts_on');
            $t->date('ends_on')->nullable();          // open-ended, for a vacancy rather than leave
            // Optional narrowing: delegate expense approvals but not new budgets, for instance.
            $t->string('subject_type', 30)->nullable();
            $t->decimal('max_amount', 18, 2)->nullable();  // "up to R50,000 while I am away"
            $t->text('reason')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'from_user_id', 'is_active']);
            $t->index(['company_id', 'to_user_id', 'is_active']);
        });

        Schema::table('budgets', function (Blueprint $t) {
            $t->unsignedBigInteger('parent_id')->nullable()->after('budget_category_id');
        });

        Schema::table('budgets', function (Blueprint $t) {
            $t->index(['company_id', 'parent_id'], 'budgets_parent_index');
        });

        $this->applyTenantRls(['budget_approval_delegations']);
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $t) {
            $t->dropIndex('budgets_parent_index');
            $t->dropColumn('parent_id');
        });

        Schema::dropIfExists('budget_approval_delegations');
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
