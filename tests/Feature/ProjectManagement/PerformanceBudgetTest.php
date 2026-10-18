<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\DerivationService;
use App\Modules\Operations\ProjectManagement\Services\SchedulingService;
use App\Modules\Tenancy\Models\Company;
use Laravel\Sanctum\Sanctum;

/**
 * The budgets in §F2, measured rather than assumed (PM-1800).
 *
 * Run at a tenth of the brief's scale so the suite stays usable, with the budgets scaled to match.
 * That is enough to catch what actually goes wrong — an accidental quadratic, an N+1 in a read
 * path — which shows up as clearly at 2,000 rows as at 20,000. The full-scale run is
 * `pm:seed-performance`, which exists precisely so the real numbers can be taken on real hardware.
 *
 * Timings on a developer's laptop are noisy, so the thresholds are generous. A test that fails
 * whenever someone opens a browser gets deleted, and then nobody measures anything.
 */
class PerformanceBudgetTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Construction']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();
        $this->manager = $this->manager->fresh();
    }

    private function fill(int $projects, int $tasks, int $evidence = 0): void
    {
        $this->artisan('pm:seed-performance', [
            '--company'  => $this->company->id,
            '--projects' => $projects,
            '--tasks'    => $tasks,
            '--evidence' => $evidence,
        ])->assertSuccessful();

        $this->project = $this->asCompany($this->company, fn () => Project::orderBy('id')->firstOrFail());
    }

    private function seconds(callable $work): float
    {
        $started = microtime(true);
        $work();

        return microtime(true) - $started;
    }

    /**
     * §F2: 20,000 tasks scheduled in five seconds. Run at 2,000, budgeted at three — the shape of
     * the failure this catches is an algorithm that went quadratic, and that is visible here.
     */
    public function test_scheduling_a_large_project_stays_within_budget(): void
    {
        $this->fill(projects: 1, tasks: 2000);

        $elapsed = $this->asCompany($this->company, fn () => $this->seconds(
            fn () => app(SchedulingService::class)->reschedule($this->project)
        ));

        $this->assertSame(2000, Task::where('project_id', $this->project->id)->count());
        $this->assertLessThan(3.0, $elapsed, "Scheduling 2,000 tasks took {$elapsed}s; the brief allows 5s for 20,000.");
    }

    /**
     * The field bundle is assembled in a yard on one bar of signal. If it is slow here it is
     * unusable there.
     */
    public function test_the_field_sync_bundle_is_assembled_quickly(): void
    {
        $this->fill(projects: 5, tasks: 200, evidence: 2);

        $this->asCompany($this->company, function () {
            ProjectMember::create([
                'project_id' => $this->project->id, 'user_id' => $this->manager->id,
                'role_key' => 'team_member', 'display_name' => 'Thabo Nkosi', 'is_active' => true,
            ]);
        });

        Sanctum::actingAs($this->manager, ['*']);

        $elapsed = $this->seconds(fn () => $this->getJson('/api/v1/pm/sync')->assertOk());

        $this->assertLessThan(3.0, $elapsed, "Assembling the sync bundle took {$elapsed}s.");
    }

    public function test_deriving_a_project_from_its_evidence_stays_within_budget(): void
    {
        $this->fill(projects: 1, tasks: 300, evidence: 5);

        $elapsed = $this->asCompany($this->company, fn () => $this->seconds(
            fn () => app(DerivationService::class)->deriveProject($this->project)
        ));

        // 1,500 events across 300 tasks. The trap here is loading evidence per task without an
        // index, which turns linear work into something a nightly run never finishes.
        $this->assertLessThan(20.0, $elapsed, "Deriving 300 tasks from 1,500 events took {$elapsed}s.");
    }

    /**
     * A page that gets slower with the size of the project is a page that stops being opened.
     *
     * Measured as a *delta* rather than a total: a page carries a fixed cost in navigation,
     * entitlements and permissions that has nothing to do with how much work is on the job. What
     * matters is whether adding 250 tasks adds 250 queries — the absolute number would only be
     * measuring the layout.
     */
    public function test_the_board_issues_the_same_queries_whatever_the_size_of_the_project(): void
    {
        $this->fill(projects: 1, tasks: 50);

        $small = $this->countQueriesLoadingTheBoard();

        $this->addTasks(250);

        $large = $this->countQueriesLoadingTheBoard();

        $this->assertSame(300, Task::where('project_id', $this->project->id)->count());
        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "50 tasks cost {$small} queries and 300 cost {$large} — the page scales with the data."
        );
    }

    private function countQueriesLoadingTheBoard(): int
    {
        $queries = 0;

        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($this->manager)
            ->get(route('project-management.board', $this->project->uuid))
            ->assertOk();

        // Laravel has no public way to detach one listener, so the counter is reset per call by
        // being a fresh closure each time; the previous ones simply increment variables nobody
        // reads any more.
        \Illuminate\Support\Facades\DB::flushQueryLog();

        return $queries;
    }

    private function addTasks(int $count): void
    {
        $rows = [];
        $now = now();

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $this->company->id,
                'project_id' => $this->project->id,
                'code' => 'X-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'name' => "Extra {$i}",
                'status' => 'not_started', 'priority' => 'medium', 'schedule_mode' => 'auto',
                'duration_minutes' => 480, 'progress_pct' => 0, 'progress_source' => 'derived',
                'currency' => 'ZAR', 'is_milestone' => false, 'is_recurrence_template' => false,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        \Illuminate\Support\Facades\DB::table('pm_tasks')->insert($rows);
    }

    public function test_the_seeder_refuses_to_guess_which_tenant_to_fill(): void
    {
        // It writes a great deal of data. Guessing the tenant is how a developer fills production.
        $this->artisan('pm:seed-performance', ['--projects' => 1, '--tasks' => 1])->assertFailed();
    }
}
