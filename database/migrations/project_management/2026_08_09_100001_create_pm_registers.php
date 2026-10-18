<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three registers every project methodology insists on (§11, §12, §13).
 *
 * They are separate tables rather than one polymorphic "items" table with a type column, because
 * they answer different questions and carry genuinely different columns:
 *
 *  pm_risks             something that has *not* happened. Probability × impact, a response
 *                       strategy, an owner. The whole point is to act before it is real.
 *  pm_issues            something that *has* happened. Severity, root cause, corrective and
 *                       preventative action. There is no probability — it is 100%.
 *  pm_change_requests   a request to move the baseline. Carries impact on scope, cost and time,
 *                       and cannot take effect without a decision.
 *
 * A shared table would have left two thirds of its columns null on every row, and every query
 * filtering on a discriminator — which is how "one flexible table" becomes three bad ones.
 *
 * **Risks become issues.** `pm_issues.risk_id` records that a risk was realised, and the risk moves
 * to status `realised` rather than being deleted. A register that quietly loses a risk the moment
 * it comes true destroys the only evidence that anybody saw it coming.
 */
return new class extends Migration
{
    private const TABLES = ['pm_risks', 'pm_issues', 'pm_change_requests'];

    public function up(): void
    {
        // ── Risks: what might go wrong ────────────────────────────────────────
        Schema::create('pm_risks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            $t->string('code', 20);                      // R-001, per project
            $t->string('title', 190);
            $t->text('description')->nullable();
            $t->string('category', 30)->default('other');

            // 1–5 each. Stored separately *and* multiplied into `score` so the register can be
            // ordered and filtered by exposure in the database rather than in the browser, which
            // is the only way the "worst first" ordering survives pagination.
            $t->unsignedTinyInteger('probability')->default(3);
            $t->unsignedTinyInteger('impact')->default(3);
            $t->unsignedSmallInteger('score')->default(9)->index();

            // avoid | reduce | transfer | accept — the four responses every standard names.
            $t->string('response_strategy', 20)->default('reduce');
            $t->text('mitigation')->nullable();
            $t->text('contingency')->nullable();         // what we do if it happens anyway

            $t->string('status', 20)->default('open');   // open | mitigating | closed | realised
            $t->unsignedBigInteger('owner_user_id')->nullable()->index();
            $t->date('review_due')->nullable();          // a risk nobody revisits is a risk nobody owns
            $t->timestamp('closed_at')->nullable();
            $t->text('closure_note')->nullable();

            // Money at stake, so a register can be ranked by exposure rather than by opinion.
            $t->decimal('cost_impact', 18, 2)->nullable();
            $t->unsignedSmallInteger('schedule_impact_days')->nullable();
            $t->string('currency', 3)->nullable();

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'status']);
        });

        // ── Issues: what has gone wrong ───────────────────────────────────────
        Schema::create('pm_issues', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            $t->string('code', 20);                      // I-001
            $t->string('title', 190);
            $t->text('description')->nullable();
            // issue | bug | defect | incident. One table because they differ by consequence and
            // routing, not by shape — and a team that has to guess which register a problem belongs
            // in files it nowhere.
            $t->string('kind', 20)->default('issue');
            $t->string('severity', 20)->default('medium');
            $t->string('priority', 20)->default('medium');
            $t->string('status', 20)->default('open');   // open | in_progress | resolved | closed

            $t->unsignedBigInteger('raised_by')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->unsignedBigInteger('task_id')->nullable()->index();
            // Set when a risk came true, so the register can prove the risk was foreseen.
            $t->unsignedBigInteger('risk_id')->nullable()->index();

            $t->date('due_date')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution')->nullable();
            // The three questions an audit asks and most trackers cannot answer.
            $t->text('root_cause')->nullable();
            $t->text('corrective_action')->nullable();
            $t->text('preventative_action')->nullable();

            $t->decimal('cost_impact', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'status']);
        });

        // ── Change requests: moving the baseline ──────────────────────────────
        Schema::create('pm_change_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            $t->string('code', 20);                      // CR-001
            $t->string('title', 190);
            $t->text('description')->nullable();
            // scope | budget | timeline | requirement — what is actually being moved.
            $t->string('kind', 20)->default('scope');
            $t->text('reason')->nullable();

            // The impact analysis. Nullable because "we do not know yet" is an honest answer and
            // forcing a zero would understate the change at exactly the moment somebody approves it.
            $t->text('impact_scope')->nullable();
            $t->decimal('impact_cost', 18, 2)->nullable();
            $t->integer('impact_days')->nullable();      // signed: a change can pull a date in
            $t->string('currency', 3)->nullable();
            $t->text('impact_quality')->nullable();
            $t->text('impact_risk')->nullable();

            $t->string('status', 20)->default('draft');  // draft | submitted | approved | rejected | implemented
            $t->unsignedBigInteger('requested_by')->nullable();
            // The approval request this was routed through, so the decision trail is the module's
            // one approval engine rather than a second one bolted on here.
            $t->uuid('approval_uuid')->nullable()->index();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamp('implemented_at')->nullable();
            // The baseline taken when it was implemented, so "what did this change actually do to
            // the plan" is answerable afterwards rather than a matter of memory.
            $t->unsignedBigInteger('baseline_id')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'status']);
        });

        foreach (self::TABLES as $table) {
            $this->partialUnique($table, ['company_id', 'project_id', 'code'], $table . '_code_unique');
        }

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Codes are unique per project, and a deleted record must not hold its number forever. */
    private function partialUnique(string $table, array $columns, string $name): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ') WHERE deleted_at IS NULL');
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
