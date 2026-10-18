<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\PmReportService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;

/**
 * Charts and BI reports (PM-1250, EPIC-070).
 *
 * The thing worth guarding hardest is that cost never reaches a role without the grant. A chart is
 * easy to hide and the number behind it is not — and an export is a file that leaves the building.
 */
class InsightsTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['budget_amount' => 500000, 'planned_start' => now()->subMonth()]);
    }

    private function seedWork(): void
    {
        $tasks = app(TaskService::class);

        $done = $tasks->create($this->project, ['name' => 'Excavate', 'duration_minutes' => 480]);
        $tasks->update($done, ['status' => 'done']);

        $late = $tasks->create($this->project, [
            'name' => 'Pour slab', 'planned_start' => now()->subWeeks(2), 'planned_end' => now()->subWeek(),
        ]);
        $tasks->update($late, ['status' => 'in_progress']);

        $tasks->create($this->project, ['name' => 'Blockwork']);
    }

    // ── Charts ───────────────────────────────────────────────────────────────

    public function test_the_dashboard_carries_its_charts(): void
    {
        $this->seedWork();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $charts = $props['charts'];

        $this->assertArrayHasKey('schedule_health', $charts);
        $this->assertArrayHasKey('task_status_mix', $charts);
        $this->assertSame(1, $charts['schedule_health']['overdue']);

        // Every status appears, including the empty ones — a donut that omits "blocked" reads as
        // a project with nothing blocked.
        $this->assertCount(5, $charts['task_status_mix']);
    }

    public function test_the_evidence_trend_fills_quiet_months_rather_than_skipping_them(): void
    {
        $props = $this->actingAs($this->manager)->get(route('project-management.insights.analytics'))
            ->assertOk()->viewData('page')['props'];

        $trend = $props['charts']['evidence_trend'];

        // Twelve months, every one present. A line that skips a quiet month draws straight across
        // it, which reads as steady activity through a period when nothing happened.
        $this->assertCount(12, $trend);
        $this->assertSame(0, $trend[0]['count']);
    }

    public function test_the_analytics_page_renders(): void
    {
        $this->seedWork();

        $this->actingAs($this->manager)
            ->get(route('project-management.insights.analytics'))
            ->assertOk();
    }

    // ── Reports ──────────────────────────────────────────────────────────────

    public function test_every_declared_report_produces_a_dataset(): void
    {
        $this->seedWork();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.insights.reports'))
            ->assertOk()
            ->viewData('page')['props'];

        foreach (array_keys(PmReportService::reportKeys()) as $key) {
            $this->assertArrayHasKey($key, $props['reports'], "The {$key} report is missing.");
            $this->assertNotEmpty($props['reports'][$key]['headers'], "{$key} has no column headers.");
        }
    }

    public function test_a_report_covers_every_part_of_the_module(): void
    {
        $areas = array_unique(array_column(PmReportService::reportKeys(), 'area'));

        // One dataset per area of the module: a BI surface that skips the money, the people or the
        // approval trail sends somebody back to querying the database directly — and then there
        // are two versions of the truth.
        foreach ([
            'projects', 'work', 'schedule', 'evidence', 'figures',
            'delivery', 'finance', 'resources', 'governance',
        ] as $area) {
            $this->assertContains($area, $areas, "No report covers the {$area} area.");
        }
    }

    /**
     * Charts cover the same ground as the reports.
     *
     * The dashboard used to answer only "how is the work going", with nothing about the money, who
     * is committed, or what is stuck waiting for a decision — which are the three questions the
     * person opening it is usually there to ask.
     */
    public function test_the_analytics_page_covers_money_people_and_approvals(): void
    {
        $props = $this->actingAs($this->manager)
            ->get(route('project-management.insights.analytics'))
            ->assertOk()
            ->viewData('page')['props'];

        foreach ([
            'cost_position', 'cost_trend', 'cost_by_category',
            'allocation_by_person', 'workload_health', 'role_mix',
            'approval_backlog',
        ] as $chart) {
            $this->assertArrayHasKey($chart, $props['charts'], "The {$chart} chart is missing.");
        }

        // Every waiting bucket is present even at zero: a bar chart that omits "over a month"
        // reads as a queue with nothing stuck in it.
        $this->assertSame(
            ['today', 'this_week', 'over_a_week', 'over_a_month'],
            array_keys($props['charts']['approval_backlog']),
        );
    }

    /**
     * A cost position that reconciles.
     *
     * Labour and materials are separate because they behave differently, and the remaining figure
     * has to be the budget less both plus what is committed — the arithmetic somebody will check
     * by hand the first time the number surprises them.
     */
    public function test_the_cost_position_adds_up(): void
    {
        $position = app(\App\Modules\Operations\ProjectManagement\Services\PmAnalyticsService::class)
            ->costPosition();

        $this->assertSame(
            round($position['budget'] - $position['labour'] - $position['other'] - $position['committed'], 2),
            $position['remaining'],
        );
    }

    public function test_the_new_datasets_all_produce_rows_without_erroring(): void
    {
        $this->seedWork();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.insights.reports'))
            ->assertOk()
            ->viewData('page')['props'];

        foreach (['cost_entries', 'cost_by_project', 'team', 'allocation', 'approvals', 'linked_records'] as $key) {
            $this->assertArrayHasKey($key, $props['reports'], "The {$key} report is missing.");
            $this->assertNotEmpty($props['reports'][$key]['headers']);
        }

        // The cost position is one row per project, which is the row a project manager is asked
        // about and the reason it is a dataset rather than three that have to be joined by hand.
        $this->assertCount(1, $props['reports']['cost_by_project']['rows']);
    }

    /** The new money reports sit behind the same grant as the old ones. */
    public function test_the_new_cost_reports_are_absent_without_the_grant(): void
    {
        $props = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.insights.reports'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('cost_entries', $props['reports']);
        $this->assertArrayNotHasKey('cost_by_project', $props['reports']);

        // Team and allocation stay: who is on the job is not a commercial secret.
        $this->assertArrayHasKey('team', $props['reports']);
        $this->assertArrayHasKey('allocation', $props['reports']);

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.insights.export', 'cost_by_project'))
            ->assertForbidden();
    }

    public function test_the_portfolio_report_reads_from_the_derivation_cache(): void
    {
        $this->seedWork();

        $props = $this->actingAs($this->manager)->get(route('project-management.insights.reports'))
            ->viewData('page')['props'];

        $row = $props['reports']['portfolio']['rows'][0];

        $this->assertSame($this->project->code, $row[0]);
        $this->assertSame(3, $row[10], 'Task count');
    }

    public function test_the_overdue_report_says_how_late_each_task_is(): void
    {
        $this->seedWork();

        $props = $this->actingAs($this->manager)->get(route('project-management.insights.reports'))
            ->viewData('page')['props'];

        $rows = $props['reports']['overdue']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Pour slab', $rows[0][2]);
        $this->assertGreaterThan(0, $rows[0][5], 'Days late');
    }

    public function test_a_project_filter_narrows_every_dataset(): void
    {
        $this->seedWork();
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        app(TaskService::class)->create($other, ['name' => 'Their work']);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.insights.reports', ['project' => $this->project->uuid]))
            ->viewData('page')['props'];

        $names = array_column($props['reports']['tasks']['rows'], 2);

        $this->assertNotContains('Their work', $names);
        $this->assertContains('Excavate', $names);
    }

    // ── Export ───────────────────────────────────────────────────────────────

    public function test_a_report_exports_as_csv(): void
    {
        $this->seedWork();

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.insights.export', 'portfolio'))
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();

        // A BOM, so Excel opens it as UTF-8 rather than mangling every accented name.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString($this->project->code, $body);
    }

    /**
     * A project name is user input, and a cell beginning `=` is a formula to Excel. The export is a
     * file that leaves the building, so it must not carry something the receiving machine executes.
     */
    public function test_an_exported_cell_cannot_be_a_spreadsheet_formula(): void
    {
        $this->makeProject(['code' => 'PRJ-X', 'name' => '=1+1']);

        $body = $this->actingAs($this->manager)
            ->get(route('project-management.insights.export', 'portfolio'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=1+1", $body);
        $this->assertStringNotContainsString(',=1+1', $body);
    }

    public function test_an_unknown_report_is_a_404(): void
    {
        $this->actingAs($this->manager)
            ->get(route('project-management.insights.export', 'nonsense'))
            ->assertNotFound();
    }

    // ── Cost is a separate grant ─────────────────────────────────────────────

    /**
     * `pm.costs.view` exists so a subcontractor can see the programme and not the margin. Hiding a
     * chart is not access control — the figures must be absent from the payload.
     */
    public function test_cost_charts_are_absent_for_a_role_without_the_grant(): void
    {
        $props = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.insights.analytics'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $props['charts']['budget_vs_actual']);
        $this->assertFalse($props['can']['costs']);

        // And present for someone who holds it.
        $manager = $this->actingAs($this->manager)
            ->get(route('project-management.insights.analytics'))
            ->viewData('page')['props'];

        $this->assertTrue($manager['can']['costs']);
    }

    public function test_cost_reports_are_absent_for_a_role_without_the_grant(): void
    {
        $props = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.insights.reports'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('labour_cost', $props['reports']);
        $this->assertArrayNotHasKey('timesheets', $props['reports']);
        $this->assertArrayHasKey('portfolio', $props['reports'], 'Non-cost reports stay available.');
    }

    public function test_exporting_a_cost_report_without_the_grant_is_refused(): void
    {
        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.insights.export', 'labour_cost'))
            ->assertForbidden();
    }

    public function test_the_dashboard_withholds_budget_from_a_role_without_the_grant(): void
    {
        $props = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $props['charts']['budget_vs_actual']);
    }

    /**
     * The page is told *why* the money charts are empty.
     *
     * The redaction empties them to `[]`, and a project with no budget captured yet arrives as `[]`
     * too — so the payload alone cannot distinguish "not for you" from "nothing entered". Without
     * this flag the dashboard cannot choose between omitting the chart and showing an empty state,
     * and it guessed wrong in one of the two cases: the card vanished and left a hole in the grid.
     */
    public function test_the_dashboard_says_whether_the_money_charts_were_withheld(): void
    {
        $withheld = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($withheld['canViewCosts']);

        $granted = $this->actingAs($this->manager)
            ->get(route('project-management.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue(
            $granted['canViewCosts'],
            'The manager holds pm.costs.view, so the dashboard should draw the budget chart — '
            . 'empty state included — rather than leaving its column blank.'
        );
    }

    public function test_labour_cost_reads_from_approved_hours_only(): void
    {
        $member = ProjectMember::create([
            'project_id' => $this->project->id, 'user_id' => $this->fieldWorker->id,
            'role_key' => 'team_member', 'display_name' => 'Thabo Nkosi', 'is_active' => true,
        ]);

        $timesheets = app(TimesheetService::class);

        $this->actingAs($this->fieldWorker);
        $approved = $timesheets->log($this->project, $member, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8, 'rate_per_hour' => 250,
        ]);
        $timesheets->submit($approved);

        // Booked but never approved: a claim, not a cost.
        $timesheets->log($this->project, $member, [
            'worked_on' => now()->subDays(2)->toDateString(), 'hours' => 5, 'rate_per_hour' => 250,
        ]);

        $this->actingAs($this->manager);
        $timesheets->approve($approved->fresh());

        $props = $this->actingAs($this->manager)->get(route('project-management.insights.reports'))
            ->viewData('page')['props'];

        $row = $props['reports']['labour_cost']['rows'][0];

        $this->assertSame(8.0, $row[2], 'Approved hours');
        $this->assertSame(5.0, $row[3], 'Unapproved hours, kept separate');
        $this->assertSame(2000.0, $row[4], 'Only approved hours are costed');
    }

    public function test_someone_with_no_grants_cannot_reach_insights_at_all(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('project-management.insights.analytics'))
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->get(route('project-management.insights.reports'))
            ->assertForbidden();
    }
}
