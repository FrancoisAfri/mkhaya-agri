<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolios, programmes, templates and stage gates (§2, §3).
 *
 * ── Why two levels of grouping and not one ──────────────────────────────────
 *
 * A programme is a set of projects delivering one outcome together (a hospital: building, IT,
 * staffing). A portfolio is a set of programmes and projects competing for the same money. They are
 * different questions — "is this outcome on track" versus "are we spending on the right things" —
 * and collapsing them into a single `parent_id` tree makes both unanswerable, because you can no
 * longer tell which level a total belongs to.
 *
 * Both are optional. A tenant running twelve unrelated jobs uses neither and sees nothing.
 *
 * ── Templates hold a payload, not rows ──────────────────────────────────────
 *
 * A template's WBS, tasks, milestones and starter risks live in one JSON document rather than in
 * shadow copies of `pm_wbs_nodes` and `pm_tasks`. Shadow tables would double every schema change
 * to those tables forever, and a template is read once at creation and never joined against.
 *
 * ── Stage gates are per project, not per template ───────────────────────────
 *
 * A gate is a decision about *this* job at *this* moment. Storing them on the template would make
 * the record of who passed the design gate on the Rosebank job a property of a template somebody
 * later edited.
 */
return new class extends Migration
{
    private const TABLES = ['pm_portfolios', 'pm_programmes', 'pm_project_templates', 'pm_stage_gates'];

    public function up(): void
    {
        Schema::create('pm_portfolios', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('code', 30)->nullable();
            $t->string('name', 190);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable()->index();
            // What this portfolio is allowed to spend, so "are we spending on the right things"
            // has a denominator.
            $t->decimal('budget_amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pm_programmes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('portfolio_id')->nullable()->index();
            $t->string('code', 30)->nullable();
            $t->string('name', 190);
            $t->text('description')->nullable();
            $t->string('outcome', 500)->nullable();      // the one thing this programme delivers
            $t->unsignedBigInteger('owner_user_id')->nullable()->index();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->decimal('budget_amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pm_project_templates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 190);
            // construction | it | marketing | manufacturing | research | government | engineering
            // | events | hr | training | other — what the template is *for*, so the picker groups.
            $t->string('industry', 40)->default('other');
            $t->string('sub_type', 60)->nullable();      // residential, roads, ERP, campaign …
            $t->text('description')->nullable();
            // The whole plan: wbs, tasks, milestones, risks, gates. One document because it is read
            // once at creation and never joined against.
            $t->jsonb('payload')->nullable();
            $t->unsignedSmallInteger('times_used')->default(0);
            $t->boolean('is_builtin')->default(false);   // shipped, not written by this tenant
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'industry', 'is_active']);
        });

        Schema::create('pm_stage_gates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            // The stage this gate guards the *exit* of.
            $t->string('stage', 40);
            $t->string('name', 190);
            $t->text('criteria')->nullable();            // what has to be true to pass
            $t->unsignedSmallInteger('position')->default(1);
            $t->string('status', 20)->default('pending'); // pending | passed | failed | waived
            $t->uuid('approval_uuid')->nullable()->index();
            $t->timestamp('decided_at')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'position']);
        });

        // `pm_projects` already carries `portfolio_id` and `program_id` from Sprint 1 — columns
        // with no tables behind them. They are adopted rather than duplicated: two portfolio
        // columns on one row is a question nobody can answer later.
        Schema::table('pm_projects', function (Blueprint $t) {
            // Which template built it. Kept so "how did we plan the last five roads jobs" is
            // answerable, and so a template's usefulness can be measured rather than assumed.
            $t->unsignedBigInteger('template_id')->nullable()->index();
        });

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        // Only the column this migration added; the other two predate it.
        Schema::table('pm_projects', fn (Blueprint $t) => $t->dropColumn(['template_id']));

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
