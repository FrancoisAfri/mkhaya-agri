<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The agile pack: sprints, epics, releases (§24).
 *
 * ── Why stories are tasks, not a new table ──────────────────────────────────
 *
 * A story *is* a task: it has an owner, an estimate, a status and a parent. Giving agile projects a
 * second work table would mean every report, every timesheet, every burn-down and every permission
 * check had to ask which of two tables to look in — and the answer would differ per project. So
 * `pm_tasks` gains `sprint_id`, `epic_id`, `story_points` and `backlog_rank`, and an agile project
 * is a project whose tasks happen to carry them.
 *
 * That also means the Kanban board, the scheduler and the timesheets work on agile projects without
 * knowing anything about agile.
 *
 * ── Why story points sit beside estimated_hours, not instead of it ──────────
 *
 * They measure different things and teams use both: points for planning capacity, hours for cost.
 * Overloading one column would make labour cost meaningless on an agile job.
 *
 * ── Backlog rank ────────────────────────────────────────────────────────────
 *
 * A float, not an integer. Dragging an item between two others is the single most common backlog
 * operation, and with integers it renumbers everything below it on every drag.
 */
return new class extends Migration
{
    private const TABLES = ['pm_epics', 'pm_sprints', 'pm_releases'];

    public function up(): void
    {
        Schema::create('pm_epics', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->string('key', 20);                       // E-01
            $t->string('name', 190);
            $t->text('description')->nullable();
            $t->string('colour', 20)->nullable();        // so a board reads at a glance
            $t->string('status', 20)->default('open');   // open | in_progress | done | cancelled
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->date('target_date')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pm_sprints', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->string('name', 190);
            $t->unsignedSmallInteger('number')->default(1);
            $t->text('goal')->nullable();                // one sentence; a sprint without one is a timebox
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 20)->default('planned'); // planned | active | closed
            // Captured when the sprint starts, so the burn-down has a fixed denominator. Without
            // it, work added mid-sprint silently rewrites history and every sprint looks on track.
            $t->decimal('committed_points', 8, 2)->nullable();
            $t->decimal('completed_points', 8, 2)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->text('retrospective')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'status']);
        });

        Schema::create('pm_releases', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->string('version', 40);                   // 2.4.0
            $t->string('name', 190)->nullable();
            $t->text('notes')->nullable();               // the release notes themselves
            $t->date('target_date')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('status', 20)->default('planned'); // planned | released | rolled_back
            $t->string('environment', 40)->nullable();   // production, staging
            // The commit or tag actually deployed. This is the one field that makes a release
            // record answerable after the fact: "what was in 2.4.0" is otherwise a guess.
            $t->string('revision', 120)->nullable();
            $t->string('pipeline_url', 500)->nullable();
            $t->unsignedBigInteger('released_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // Agile is a set of columns on the existing work table, not a parallel one.
        Schema::table('pm_tasks', function (Blueprint $t) {
            $t->unsignedBigInteger('sprint_id')->nullable()->index();
            $t->unsignedBigInteger('epic_id')->nullable()->index();
            $t->unsignedBigInteger('release_id')->nullable()->index();
            $t->decimal('story_points', 6, 2)->nullable();
            // Float so an item can be dropped between two others without renumbering the backlog.
            $t->double('backlog_rank')->nullable()->index();
        });

        foreach (['pm_epics' => 'key', 'pm_sprints' => 'name', 'pm_releases' => 'version'] as $table => $column) {
            $this->partialUnique($table, ['company_id', 'project_id', $column], $table . '_ref_unique');
        }

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        Schema::table('pm_tasks', fn (Blueprint $t) => $t->dropColumn([
            'sprint_id', 'epic_id', 'release_id', 'story_points', 'backlog_rank',
        ]));

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

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
