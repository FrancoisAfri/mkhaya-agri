<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budget tracking: thresholds that raise alerts, and thresholds that stop spending.
 *
 * The module already knew, precisely, how much of every budget was reserved, committed and spent.
 * Nobody was told. A budget crossed 90% on a Tuesday and the first anyone heard of it was a
 * purchase order that would not go through — or worse, one that did, because the only check was a
 * page nobody had opened.
 *
 * Two tables, and the distinction between them is the point:
 *
 *   budget_alert_rules  what the company considers worth knowing about, and at what point it stops
 *                       being a warning and becomes a refusal. Per tenant, because a municipality
 *                       and a two-person contractor do not agree on when to panic.
 *   budget_alerts       what has actually happened, one row per (budget, rule) while it is live, so
 *                       the nightly sweep does not send the same warning every night for a month.
 *
 * `severity` is ordered: info → warning → critical → block. Only `block` refuses new encumbrances,
 * and it does so at the point money is committed rather than at the point somebody looks at a
 * screen — a rule enforced only in the UI is a rule enforced only on the honest.
 *
 * Rules are optional. A tenant with none behaves as it did before, except that a budget whose
 * available balance has gone negative is still reported, because that is not a preference.
 */
return new class extends Migration
{
    private const TABLES = ['budget_alert_rules', 'budget_alerts'];

    public function up(): void
    {
        Schema::create('budget_alert_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 120);

            // What the rule applies to. 'all' is the common case; the others exist because capital
            // and operating budgets are not watched the same way, and one runaway category should
            // not need the whole tenant put on a tighter leash.
            $t->string('scope_type', 20)->default('all');   // all | expenditure_type | category | budget
            $t->string('scope_value', 20)->nullable();      // capex | opex, when scope_type says so
            $t->unsignedBigInteger('scope_id')->nullable(); // category id or budget id

            // What is measured. Percentages are of the allocation (plus approved overrides):
            //   committed_pct  reserved + committed + actual — the money that is no longer free
            //   actual_pct     actual only — what has genuinely left the account
            //   available_amt  the absolute balance, for "tell me when there is under R50,000 left"
            $t->string('metric', 20)->default('committed_pct');
            $t->decimal('threshold', 18, 2);                 // percent, or an amount for available_amt

            $t->string('severity', 12)->default('warning');  // info | warning | critical | block
            $t->boolean('notify_owners')->default(true);
            $t->json('notify_roles')->nullable();            // extra platform roles to copy
            $t->boolean('is_active')->default(true);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_active', 'severity']);
        });

        Schema::create('budget_alerts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('budget_id')->index();
            $t->unsignedBigInteger('rule_id')->nullable();
            $t->string('severity', 12);
            $t->string('metric', 20);
            $t->decimal('threshold', 18, 2)->nullable();
            $t->decimal('observed', 18, 2)->nullable();      // what it actually was when raised
            $t->string('message', 255)->nullable();
            $t->string('status', 16)->default('open');       // open | acknowledged | resolved
            $t->unsignedBigInteger('acknowledged_by')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('acknowledgement')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            // Bumped every sweep the condition still holds, so "open since" and "still true as of"
            // are different questions with different answers.
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();

            $t->foreign('rule_id')->references('id')->on('budget_alert_rules')->nullOnDelete();
            $t->index(['company_id', 'status', 'severity']);
            $t->index(['company_id', 'budget_id', 'status']);
        });

        // One live alert per (budget, rule). Without this the nightly sweep raises the same warning
        // every night and the page becomes a wall nobody reads.
        $this->partialUnique(
            'budget_alerts',
            ['company_id', 'budget_id', 'rule_id', 'metric'],
            'budget_alerts_live_unique',
            "status <> 'resolved'"
        );

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function partialUnique(string $table, array $columns, string $name, string $where): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ') WHERE ' . $where);
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
